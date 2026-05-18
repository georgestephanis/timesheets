const {getDefaultConfig, mergeConfig} = require('@react-native/metro-config');
const path = require('path');

// Workspace root — packages hoisted here by npm workspaces
const workspaceRoot = path.resolve(__dirname, '../..');
const appRoot = __dirname;

const config = {
  watchFolders: [workspaceRoot],
  resolver: {
    nodeModulesPaths: [
      path.resolve(appRoot, 'node_modules'),
      path.resolve(workspaceRoot, 'node_modules'),
    ],
    blockList: [
      // Don't watch node_modules trees — only source packages need watching.
      new RegExp(`${workspaceRoot}/node_modules/.*`),
      new RegExp(`${appRoot}/node_modules/.*`),
    ],
  },
};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);
