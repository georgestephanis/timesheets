import Foundation
import Network
import FoundationModels

/// OpenAI-compatible HTTP shim backed by Apple's on-device FoundationModels.
///
/// Listens on a fixed loopback port (57911) so config.json can permanently
/// reference `http://127.0.0.1:57911/v1` without the address changing between
/// launches.  If FoundationModels is unavailable (macOS < 26 or Apple
/// Intelligence not configured), `start()` is a no-op and `runningPort` returns 0.
///
/// Endpoints:
///   GET  /v1/models              — returns a single "apple-intelligence" model
///   POST /v1/chat/completions    — OpenAI chat completions format
@available(macOS 26.0, *)
@objc public final class FoundationModelsServer: NSObject {

    static let serverPort: UInt16 = 57911

    @objc public static let shared = FoundationModelsServer()

    private var listener: NWListener?
    private let queue = DispatchQueue(label: "com.timesheets.apple-intelligence", qos: .utility)

    @objc public var runningPort: Int {
        listener != nil ? Int(Self.serverPort) : 0
    }

    @objc public func start() {
        guard listener == nil else { return }
        guard SystemLanguageModel(guardrails: .default).isAvailable else { return }

        do {
            let params = NWParameters.tcp
            params.allowLocalEndpointReuse = true
            let l = try NWListener(using: params,
                                   on: NWEndpoint.Port(rawValue: Self.serverPort)!)
            l.newConnectionHandler = { [weak self] connection in
                self?.handle(connection)
            }
            l.start(queue: queue)
            listener = l
        } catch {
            // Port already in use or NWListener setup failed — Apple Intelligence
            // will not be available as an LLM option this session.
        }
    }

    @objc public func stop() {
        listener?.cancel()
        listener = nil
    }

    // MARK: — Connection handling

    private func handle(_ connection: NWConnection) {
        connection.start(queue: queue)
        connection.receive(minimumIncompleteLength: 1,
                           maximumLength: 131_072) { [weak self] data, _, _, _ in
            guard let self, let data, !data.isEmpty else {
                connection.cancel()
                return
            }
            self.dispatch(data: data, connection: connection)
        }
    }

    private func dispatch(data: Data, connection: NWConnection) {
        guard let raw = String(data: data, encoding: .utf8) else {
            respond(connection: connection, status: 400, body: errorJSON("Bad encoding"))
            return
        }

        let head: String
        let body: String
        if let sep = raw.range(of: "\r\n\r\n") {
            head = String(raw[raw.startIndex..<sep.lowerBound])
            body = String(raw[sep.upperBound...])
        } else {
            head = raw
            body = ""
        }

        let requestLine = head.components(separatedBy: "\r\n").first ?? ""
        let parts = requestLine.components(separatedBy: " ")
        guard parts.count >= 2 else {
            respond(connection: connection, status: 400, body: errorJSON("Malformed request"))
            return
        }

        let method = parts[0]
        let path   = parts[1].components(separatedBy: "?").first ?? parts[1]

        switch (method, path) {
        case ("GET",  "/v1/models"):
            respond(connection: connection, status: 200, body: modelsJSON())
        case ("POST", "/v1/chat/completions"):
            handleCompletion(body: body, connection: connection)
        default:
            respond(connection: connection, status: 404, body: errorJSON("Not found"))
        }
    }

    // MARK: — Endpoints

    private func handleCompletion(body: String, connection: NWConnection) {
        guard
            let bodyData = body.data(using: .utf8),
            let obj = try? JSONSerialization.jsonObject(with: bodyData) as? [String: Any]
        else {
            respond(connection: connection, status: 400, body: errorJSON("Invalid JSON"))
            return
        }

        let messages = obj["messages"] as? [[String: Any]] ?? []
        let prompt   = buildPrompt(messages)

        Task {
            do {
                let model   = SystemLanguageModel(guardrails: .default)
                let session = LanguageModelSession(model: model)
                let stream  = session.streamResponse(to: prompt)

                var content = ""
                for try await snapshot in stream {
                    content = snapshot.content
                }

                self.respond(connection: connection, status: 200,
                             body: self.completionJSON(content))
            } catch {
                self.respond(connection: connection, status: 500,
                             body: self.errorJSON(error.localizedDescription))
            }
        }
    }

    // MARK: — Helpers

    private func buildPrompt(_ messages: [[String: Any]]) -> String {
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
        // Hard cap to stay within FoundationModels' 4096-token context window.
        if prompt.count > 12_000 {
            prompt = String(prompt.prefix(12_000))
                   + "\n[prompt truncated to fit context window]"
        }
        return prompt
    }

    private func modelsJSON() -> String {
        #"{"object":"list","data":[{"id":"apple-intelligence","object":"model","created":0,"owned_by":"apple"}]}"#
    }

    private func completionJSON(_ text: String) -> String {
        let escaped = jsonEscape(text)
        let ts = Int(Date().timeIntervalSince1970)
        return
            #"{"id":"chatcmpl-apple","object":"chat.completion","created":"# + "\(ts)" +
            #","model":"apple-intelligence","choices":[{"index":0,"message":{"role":"assistant","content":""# +
            escaped +
            #""},"finish_reason":"stop"}],"usage":{"prompt_tokens":0,"completion_tokens":0,"total_tokens":0}}"#
    }

    private func errorJSON(_ message: String) -> String {
        let escaped = jsonEscape(message)
        return #"{"error":{"message":""# + escaped + #"","type":"server_error"}}"#
    }

    private func jsonEscape(_ s: String) -> String {
        s.replacingOccurrences(of: "\\", with: "\\\\")
         .replacingOccurrences(of: "\"", with: "\\\"")
         .replacingOccurrences(of: "\n", with: "\\n")
         .replacingOccurrences(of: "\r", with: "\\r")
         .replacingOccurrences(of: "\t", with: "\\t")
    }

    private func respond(connection: NWConnection, status: Int, body: String) {
        let bodyData = Data(body.utf8)
        let statusText = status == 200 ? "OK" : "Error"
        let header = "HTTP/1.1 \(status) \(statusText)\r\n"
                   + "Content-Type: application/json\r\n"
                   + "Content-Length: \(bodyData.count)\r\n"
                   + "Access-Control-Allow-Origin: *\r\n"
                   + "Connection: close\r\n\r\n"
        var packet = Data(header.utf8)
        packet.append(bodyData)
        connection.send(content: packet, completion: .contentProcessed { _ in
            connection.cancel()
        })
    }
}
