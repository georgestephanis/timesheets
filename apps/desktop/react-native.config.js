const rnMacosDir = require.resolve("react-native-macos/package.json").replace("/package.json", "");
const { bundleCommand, startCommand } = require(
    require.resolve("@react-native/community-cli-plugin", { paths: [rnMacosDir] }),
);
const macosCommands = require("react-native-macos/local-cli/runMacOS/runMacOS");
const apple = require("@react-native-community/cli-platform-apple");

module.exports = {
    commands: [bundleCommand, startCommand, ...macosCommands],
    platforms: {
        macos: {
            linkConfig: () => ({
                isInstalled: () => false,
                register: () => {},
                unregister: () => {},
                copyAssets: () => {},
                unlinkAssets: () => {},
            }),
            projectConfig: apple.getProjectConfig({ platformName: "macos" }),
            dependencyConfig: apple.getDependencyConfig({ platformName: "macos" }),
            npmPackageName: "react-native-macos",
        },
    },
    project: {
        ios: {
            sourceDir: "./macos",
        },
    },
};
