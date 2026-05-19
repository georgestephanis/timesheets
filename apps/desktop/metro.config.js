const {getDefaultConfig, mergeConfig} = require('@react-native/metro-config');
const path = require('path');

// Workspace root — packages hoisted here by npm workspaces
const workspaceRoot = path.resolve(__dirname, '../..');
const appRoot = __dirname;

const config = {
  // Watch the full workspace so Metro can see node_modules hoisted to the root.
  // Watchman is configured via .watchmanconfig to exclude node_modules from
  // file-change notifications, keeping the watch set small.
  watchFolders: [workspaceRoot],
  resolver: {
    nodeModulesPaths: [
      path.resolve(appRoot, 'node_modules'),
      path.resolve(workspaceRoot, 'node_modules'),
    ],
  },
};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);
