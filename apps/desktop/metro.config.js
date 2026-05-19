const {getDefaultConfig, mergeConfig} = require('@react-native/metro-config');
const path = require('path');

// Workspace root — packages hoisted here by npm workspaces
const workspaceRoot = path.resolve(__dirname, '../..');
const appRoot = __dirname;

const config = {
  // Only watch source packages that need hot-reload — not the entire workspace
  // root (which would pull in node_modules and cause Watchman inode overflow).
  watchFolders: [
    path.resolve(workspaceRoot, 'packages/ui'),
    path.resolve(workspaceRoot, 'packages/contracts'),
  ],
  resolver: {
    nodeModulesPaths: [
      path.resolve(appRoot, 'node_modules'),
      path.resolve(workspaceRoot, 'node_modules'),
    ],
  },
};

module.exports = mergeConfig(getDefaultConfig(__dirname), config);
