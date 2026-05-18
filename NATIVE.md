# React Native Desktop Implementation Plan

This document outlines the plan for implementing a React Native Desktop version of the timesheets application based on the existing PHP implementation.

## Overview

The goal is to create a cross-platform desktop application using React Native that replicates the functionality of the existing timesheets tool. The application will:

1. Aggregate activity data from multiple sources (ActivityWatch, Chrome, Git, external APIs)
2. Classify and attribute events to projects
3. Display reports in a user-friendly interface
4. Support desktop platforms (macOS, Windows, Linux)

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   UI Layer      │    │   Engine Layer  │    │   Data Sources  │
│                 │    │                 │    │                 │
│  React Native   │───▶│  TypeScript     │───▶│  ActivityWatch  │
│  Components     │    │  Engine         │    │  Chrome         │
│                 │    │                 │    │  Git            │
│                 │    │                 │    │  Integrations   │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## Packages Structure

### 1. Contracts (`@timesheets/contracts`)

Defines types and interfaces for data structures used throughout the application.

### 2. Engine (`@timesheets/engine`)

Core business logic implemented in TypeScript, replacing the PHP functionality:

- Data loading and caching
- Report generation
- Configuration management
- Source processing (ActivityWatch, Chrome, Git, external APIs)

### 3. UI (`@timesheets/ui`)

React Native components for the desktop application interface:

- Report display components
- Configuration panels
- Timeline visualization
- Menubar/taskbar integration

### 4. Test Fixtures (`@timesheets/test-fixtures`)

Test data and fixtures for unit/integration testing

## Implementation Plan

### Phase 1: Package Structure Setup

- [x] Create monorepo structure with packages
- [x] Define contracts for data structures
- [x] Set up engine package with core functionality

### Phase 2: UI Development

- [x] Create React Native components for report display
- [x] Implement timeline visualization
- [x] Add project cards and details view

### Phase 3: Data Processing Implementation

- [ ] Implement ActivityWatch data loading
- [ ] Implement Chrome history data loading
- [ ] Implement Git commit data loading
- [ ] Implement external API integrations
- [ ] Implement data classification and aggregation

### Phase 4: Desktop Features

- [ ] Implement menubar/taskbar integration
- [ ] Add system tray functionality
- [ ] Implement background processing
- [ ] Add notification support

### Phase 5: Testing and Optimization

- [ ] Unit testing for core engine logic
- [ ] Integration testing for data flows
- [ ] Performance optimization
- [ ] Cross-platform compatibility testing

## Technology Stack

- **Framework**: React Native (with react-native-macos, react-native-windows)
- **Language**: TypeScript
- **Build Tools**: Metro bundler, React Native CLI
- **State Management**: React hooks and context
- **UI Components**: React Native primitives
- **Testing**: Jest, React Testing Library

## Key Features Implementation

### 1. Data Sources Integration

#### ActivityWatch

- Connect to ActivityWatch SQLite database
- Parse window focus events
- Handle AFK status detection
- Process input slices (mouse/keyboard activity)

#### Chrome History

- Access Chrome SQLite database
- Parse browsing events
- Handle URL resolution
- Correlate with window focus data

#### Git

- Read Git repositories from configured paths
- Parse commit history
- Attribute commits to projects based on repository mapping

#### External APIs

- Harvest integration
- ClickUp integration
- Clockify integration
- GitHub integration (CLI-based)

### 2. Reporting Engine

- Date range selection (7 days, 30 days, custom)
- Project attribution logic
- Activity ratio calculation
- Timeline visualization
- Export functionality (JSON, TSV, Markdown)

### 3. Desktop Features

- Menubar/taskbar integration (macOS)
- System tray icons (Windows/Linux)
- Background processing
- Notification support
- Auto-update capability

## Migration Approach

Since the existing application is written in PHP, we'll need to:

1. Translate PHP logic to TypeScript/JavaScript
2. Replace database interactions with native Node.js libraries
3. Adapt file I/O operations for cross-platform compatibility
4. Maintain the same data models and APIs
5. Preserve existing configuration format

## Challenges and Solutions

### Challenge: Platform Differences

**Solution**: Use React Native with platform-specific components and libraries (react-native-macos, react-native-windows)

### Challenge: Native System Integration

**Solution**: Use platform-specific APIs for menubar/taskbar integration, system notifications, and background processes

### Challenge: Database Access

**Solution**: Use Node.js libraries for SQLite access and implement platform-appropriate file paths

## Next Steps

1. Complete data source implementation in engine
2. Implement desktop-specific features
3. Add testing and validation
4. Optimize for performance across platforms
5. Document the API and development process
