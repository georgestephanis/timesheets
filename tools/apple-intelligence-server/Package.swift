// swift-tools-version: 6.0
import PackageDescription

let package = Package(
    name: "apple-intelligence-server",
    platforms: [
        .macOS("26.0"),
    ],
    targets: [
        .executableTarget(
            name: "apple-intelligence-server",
            path: "Sources",
            linkerSettings: [
                .linkedFramework("FoundationModels"),
                .linkedFramework("Network"),
            ]
        ),
    ]
)
