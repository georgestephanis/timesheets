// Standalone OpenAI-compatible HTTP server backed by Apple's on-device FoundationModels.
// Listens on 127.0.0.1:57911 — same port as the desktop app's built-in shim — so
// config.json needs only one Apple Intelligence entry regardless of whether the PHP
// web UI or the macOS app is handling the request.
//
// Build:  swift build -c release
// Binary: .build/release/apple-intelligence-server
// Run:    .build/release/apple-intelligence-server   (PHP starts it automatically)

import Foundation
import Network
import FoundationModels

let kPort: UInt16 = 57911
let serverQueue = DispatchQueue(label: "com.timesheets.apple-intelligence", qos: .utility)

// Availability guard — exit cleanly so PHP's fsockopen polling doesn't hang.
guard SystemLanguageModel.default.isAvailable else {
    fputs("apple-intelligence-server: Apple Intelligence is not available on this device.\n"
        + "Enable it in System Settings → Apple Intelligence & Siri.\n", stderr)
    exit(1)
}

let params = NWParameters.tcp
params.allowLocalEndpointReuse = true

let listener: NWListener
do {
    listener = try NWListener(using: params, on: NWEndpoint.Port(rawValue: kPort)!)
} catch {
    // Port already bound — most likely the desktop app is running its own shim.
    // Exit silently; the existing listener will handle requests.
    exit(0)
}

listener.stateUpdateHandler = { state in
    if case .failed(let err) = state {
        fputs("apple-intelligence-server: listener failed: \(err)\n", stderr)
        exit(1)
    }
}

listener.newConnectionHandler = { connection in
    connection.start(queue: serverQueue)
    receive(connection: connection)
}

listener.start(queue: serverQueue)

signal(SIGTERM) { _ in exit(0) }
signal(SIGINT)  { _ in exit(0) }

dispatchMain()

// MARK: — Connection handling

func receive(connection: NWConnection) {
    connection.receive(minimumIncompleteLength: 1, maximumLength: 131_072) { data, _, _, _ in
        guard let data, !data.isEmpty else { connection.cancel(); return }
        route(data: data, connection: connection)
    }
}

func route(data: Data, connection: NWConnection) {
    guard let raw = String(data: data, encoding: .utf8) else {
        respond(connection: connection, status: 400, body: errJSON("Bad encoding"))
        return
    }

    let head: String
    let body: String
    if let sep = raw.range(of: "\r\n\r\n") {
        head = String(raw[raw.startIndex..<sep.lowerBound])
        body = String(raw[sep.upperBound...])
    } else {
        head = raw; body = ""
    }

    let requestLine = head.components(separatedBy: "\r\n").first ?? ""
    let parts       = requestLine.components(separatedBy: " ")
    guard parts.count >= 2 else {
        respond(connection: connection, status: 400, body: errJSON("Malformed request"))
        return
    }

    let method = parts[0]
    let path   = parts[1].components(separatedBy: "?").first ?? parts[1]

    switch (method, path) {
    case ("GET", "/v1/models"):
        respond(connection: connection, status: 200,
                body: #"{"object":"list","data":[{"id":"apple-intelligence","object":"model","created":0,"owned_by":"apple"}]}"#)
    case ("POST", "/v1/chat/completions"):
        handleCompletion(body: body, connection: connection)
    default:
        respond(connection: connection, status: 404, body: errJSON("Not found"))
    }
}

// MARK: — Chat completions

func handleCompletion(body: String, connection: NWConnection) {
    guard
        let bodyData = body.data(using: .utf8),
        let obj      = try? JSONSerialization.jsonObject(with: bodyData) as? [String: Any]
    else {
        respond(connection: connection, status: 400, body: errJSON("Invalid JSON"))
        return
    }

    let messages = obj["messages"] as? [[String: Any]] ?? []
    let prompt   = buildPrompt(messages)

    Task {
        do {
            // Use the same session/streaming pattern as apfel (streamResponse, not respond).
            let model   = SystemLanguageModel(guardrails: .default)
            let session = LanguageModelSession(model: model)
            let stream  = session.streamResponse(to: prompt)

            var content = ""
            for try await snapshot in stream {
                content = snapshot.content  // accumulated text (last value = full response)
            }

            respond(connection: connection, status: 200, body: completionJSON(content))
        } catch {
            respond(connection: connection, status: 500,
                    body: errJSON(error.localizedDescription))
        }
    }
}

// MARK: — Helpers

func buildPrompt(_ messages: [[String: Any]]) -> String {
    var parts: [String] = []
    for msg in messages {
        let role    = msg["role"]    as? String ?? "user"
        let content = msg["content"] as? String ?? ""
        switch role {
        case "system":    parts.append("System: \(content)")
        case "assistant": parts.append("Assistant: \(content)")
        default:          parts.append("User: \(content)")
        }
    }
    var prompt = parts.joined(separator: "\n\n")
    // Hard cap to stay within the 4096-token context window.
    if prompt.count > 12_000 {
        prompt = String(prompt.prefix(12_000)) + "\n[prompt truncated to fit context window]"
    }
    return prompt
}

func completionJSON(_ text: String) -> String {
    let escaped = esc(text)
    let ts = Int(Date().timeIntervalSince1970)
    return """
    {"id":"chatcmpl-apple","object":"chat.completion","created":\(ts),"model":"apple-intelligence",\
    "choices":[{"index":0,"message":{"role":"assistant","content":"\(escaped)"},"finish_reason":"stop"}],\
    "usage":{"prompt_tokens":0,"completion_tokens":0,"total_tokens":0}}
    """
}

func errJSON(_ msg: String) -> String {
    #"{"error":{"message":"\#(esc(msg))","type":"server_error"}}"#
}

func esc(_ s: String) -> String {
    s.replacingOccurrences(of: "\\", with: "\\\\")
     .replacingOccurrences(of: "\"", with: "\\\"")
     .replacingOccurrences(of: "\n", with: "\\n")
     .replacingOccurrences(of: "\r", with: "\\r")
     .replacingOccurrences(of: "\t", with: "\\t")
}

func respond(connection: NWConnection, status: Int, body: String) {
    let bodyData   = Data(body.utf8)
    let statusText = status == 200 ? "OK" : "Error"
    let header     = "HTTP/1.1 \(status) \(statusText)\r\n"
                   + "Content-Type: application/json\r\n"
                   + "Content-Length: \(bodyData.count)\r\n"
                   + "Access-Control-Allow-Origin: *\r\n"
                   + "Connection: close\r\n\r\n"
    var packet = Data(header.utf8)
    packet.append(bodyData)
    connection.send(content: packet, completion: .contentProcessed { _ in connection.cancel() })
}
