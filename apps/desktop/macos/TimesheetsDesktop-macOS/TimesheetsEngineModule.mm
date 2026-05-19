#import "TimesheetsEngineModule.h"
#import <React/RCTLog.h>
#import <UniformTypeIdentifiers/UniformTypeIdentifiers.h>
#include <sys/select.h>
#include <unistd.h>

static NSString *const kConfigDirKey       = @"TimesheetsConfigDir";
static NSString *const kEngineScriptKey    = @"TimesheetsEngineScript";

static NSString *defaultConfigPath(void)
{
  return [NSHomeDirectory() stringByAppendingPathComponent:@".config/timesheets/config.json"];
}

// ─── Private ivars ────────────────────────────────────────────────────────────

@interface TimesheetsEngineModule ()
{
  NSTask   *_sidecarTask;
  int       _sidecarPort;
  NSString *_nodePath;
}
@end

// ─── Implementation ───────────────────────────────────────────────────────────

@implementation TimesheetsEngineModule

RCT_EXPORT_MODULE(TimesheetsEngine);

- (NSString *)resolvedConfigPath
{
  NSString *dir = [[NSUserDefaults standardUserDefaults] stringForKey:kConfigDirKey];
  if (dir.length) return [dir stringByAppendingPathComponent:@"config.json"];
  return defaultConfigPath();
}

// ── getConfigPath ─────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(getConfigPath:(RCTPromiseResolveBlock)resolve
                        reject:(RCTPromiseRejectBlock)reject)
{
  resolve([self resolvedConfigPath]);
}

// ── setConfigDir ──────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(setConfigDir:(NSString *)dir
                      resolve:(RCTPromiseResolveBlock)resolve
                       reject:(RCTPromiseRejectBlock)reject)
{
  [[NSUserDefaults standardUserDefaults] setObject:dir forKey:kConfigDirKey];
  resolve([dir stringByAppendingPathComponent:@"config.json"]);
}

// ── getConfig ─────────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(getConfig:(RCTPromiseResolveBlock)resolve
                    reject:(RCTPromiseRejectBlock)reject)
{
  NSString *path = [self resolvedConfigPath];
  NSFileManager *fm = [NSFileManager defaultManager];

  if (![fm fileExistsAtPath:path]) { resolve([NSNull null]); return; }

  NSError *readErr;
  NSData *data = [NSData dataWithContentsOfFile:path options:0 error:&readErr];
  if (readErr) { reject(@"READ_ERROR", readErr.localizedDescription, readErr); return; }

  NSError *jsonErr;
  id parsed = [NSJSONSerialization JSONObjectWithData:data
                                             options:NSJSONReadingMutableContainers
                                               error:&jsonErr];
  if (jsonErr) { reject(@"PARSE_ERROR", jsonErr.localizedDescription, jsonErr); return; }
  resolve(parsed);
}

// ── saveConfig ────────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(saveConfig:(NSDictionary *)config
                    resolve:(RCTPromiseResolveBlock)resolve
                     reject:(RCTPromiseRejectBlock)reject)
{
  NSString *path = [self resolvedConfigPath];
  NSFileManager *fm = [NSFileManager defaultManager];
  NSString *dir = [path stringByDeletingLastPathComponent];

  NSError *dirErr;
  if (![fm createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:&dirErr]) {
    reject(@"DIR_ERROR", dirErr.localizedDescription, dirErr); return;
  }

  if ([fm fileExistsAtPath:path]) {
    NSString *backupDir = [dir stringByAppendingPathComponent:@"config"];
    [fm createDirectoryAtPath:backupDir withIntermediateDirectories:YES attributes:nil error:nil];
    NSDateFormatter *fmt = [[NSDateFormatter alloc] init];
    fmt.dateFormat = @"yyyy-MM-dd'T'HH-mm-ss";
    NSString *stamp = [fmt stringFromDate:[NSDate date]];
    NSString *backupPath = [backupDir stringByAppendingPathComponent:
                            [NSString stringWithFormat:@"config-%@.json", stamp]];
    [fm copyItemAtPath:path toPath:backupPath error:nil];
  }

  NSError *jsonErr;
  NSData *data = [NSJSONSerialization dataWithJSONObject:config
                                                options:NSJSONWritingPrettyPrinted
                                                  error:&jsonErr];
  if (jsonErr) { reject(@"SERIALIZE_ERROR", jsonErr.localizedDescription, jsonErr); return; }

  NSMutableData *out = [data mutableCopy];
  [out appendBytes:"\n" length:1];
  NSError *writeErr;
  if (![out writeToFile:path options:NSDataWritingAtomic error:&writeErr]) {
    reject(@"WRITE_ERROR", writeErr.localizedDescription, writeErr); return;
  }
  resolve(path);
}

// ── pickConfigDir ─────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(pickConfigDir:(RCTPromiseResolveBlock)resolve
                        reject:(RCTPromiseRejectBlock)reject)
{
  dispatch_async(dispatch_get_main_queue(), ^{
    NSOpenPanel *panel = [NSOpenPanel openPanel];
    panel.canChooseFiles = NO;
    panel.canChooseDirectories = YES;
    panel.canCreateDirectories = YES;
    panel.prompt = @"Select Config Directory";
    panel.message = @"Choose the directory that contains (or will contain) config.json";

    if ([panel runModal] == NSModalResponseOK) {
      NSString *dir = panel.URL.path;
      [[NSUserDefaults standardUserDefaults] setObject:dir forKey:kConfigDirKey];
      resolve([dir stringByAppendingPathComponent:@"config.json"]);
    } else {
      resolve([NSNull null]);
    }
  });
}

// ═══════════════════════════════════════════════════════════════════════════════
// MARK: — Sidecar (engine HTTP server) management
// ═══════════════════════════════════════════════════════════════════════════════

// ── getEngineScriptPath ───────────────────────────────────────────────────────
// Returns the stored engine-server.js path or null.

RCT_EXPORT_METHOD(getEngineScriptPath:(RCTPromiseResolveBlock)resolve
                               reject:(RCTPromiseRejectBlock)reject)
{
  // 1. Check NSUserDefaults.
  NSString *stored = [[NSUserDefaults standardUserDefaults] stringForKey:kEngineScriptKey];
  if (stored.length) { resolve(stored); return; }

  // 2. Bundle fallback — only usable when node_modules are bundled alongside
  //    (i.e. a packaged production build). In development the bundle has
  //    engine-server.js but no node_modules, so return null to prompt the user
  //    to locate the dev workspace copy via pickEngineScript.
  NSString *bundled = [[NSBundle mainBundle] pathForResource:@"engine-server" ofType:@"js"];
  if (bundled && [self findNodeModulesForScript:bundled]) { resolve(bundled); return; }

  resolve([NSNull null]);
}

// ── setEngineScriptPath ───────────────────────────────────────────────────────

RCT_EXPORT_METHOD(setEngineScriptPath:(NSString *)path
                               resolve:(RCTPromiseResolveBlock)resolve
                                reject:(RCTPromiseRejectBlock)reject)
{
  [[NSUserDefaults standardUserDefaults] setObject:path forKey:kEngineScriptKey];
  resolve(path);
}

// ── pickEngineScript ──────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(pickEngineScript:(RCTPromiseResolveBlock)resolve
                            reject:(RCTPromiseRejectBlock)reject)
{
  dispatch_async(dispatch_get_main_queue(), ^{
    NSOpenPanel *panel = [NSOpenPanel openPanel];
    panel.canChooseFiles = YES;
    panel.canChooseDirectories = NO;
    panel.allowedContentTypes = @[[UTType typeWithIdentifier:@"com.netscape.javascript-source"]];
    panel.prompt = @"Select";
    panel.message = @"Locate engine-server.js (in apps/desktop/ inside your Timesheets repo)";

    if ([panel runModal] == NSModalResponseOK) {
      NSString *path = panel.URL.path;
      [[NSUserDefaults standardUserDefaults] setObject:path forKey:kEngineScriptKey];
      resolve(path);
    } else {
      resolve([NSNull null]);
    }
  });
}

// ── findNodeModulesForScript ──────────────────────────────────────────────────
// Walks up from the script's directory until it finds a node_modules folder
// containing @timesheets/engine. Returns nil if none found.

- (NSString *)findNodeModulesForScript:(NSString *)scriptPath
{
  NSFileManager *fm = [NSFileManager defaultManager];
  NSString *dir = [scriptPath stringByDeletingLastPathComponent];

  while (dir.length > 1) {
    NSString *candidate = [[dir stringByAppendingPathComponent:@"node_modules"]
                                stringByAppendingPathComponent:@"@timesheets/engine"];
    if ([fm fileExistsAtPath:candidate]) {
      return [dir stringByAppendingPathComponent:@"node_modules"];
    }
    NSString *parent = [dir stringByDeletingLastPathComponent];
    if ([parent isEqualToString:dir]) break; // reached filesystem root
    dir = parent;
  }
  return nil;
}

// ── resolveNodeBinary ─────────────────────────────────────────────────────────
// Checks the app bundle first (packaged builds), then falls back to the login
// shell's `which node` (development builds). Result is cached after first call.

- (NSString *)resolveNodeBinary
{
  if (_nodePath) return _nodePath;

  // 1. Prefer bundled node binary (present in distribution builds after running
  //    tools/bundle-engine.sh and adding the engine/ folder to Xcode resources).
  NSString *bundledNode = [[[NSBundle mainBundle] resourcePath]
                           stringByAppendingPathComponent:@"engine/node"];
  if ([[NSFileManager defaultManager] isExecutableFileAtPath:bundledNode]) {
    _nodePath = bundledNode;
    return _nodePath;
  }

  // 2. Fall back to system node via login shell (development builds).
  NSTask *task = [NSTask new];
  task.launchPath = @"/bin/zsh";
  task.arguments = @[@"-l", @"-c", @"which node"];
  NSPipe *pipe = [NSPipe pipe];
  task.standardOutput = pipe;
  task.standardError = [NSFileHandle fileHandleWithNullDevice];

  @try {
    [task launch];
    [task waitUntilExit];
  } @catch (NSException *e) {
    return nil;
  }

  NSData *data = [pipe.fileHandleForReading readDataToEndOfFile];
  NSString *result = [[NSString alloc] initWithData:data encoding:NSUTF8StringEncoding];
  result = [result stringByTrimmingCharactersInSet:[NSCharacterSet whitespaceAndNewlineCharacterSet]];

  if (result.length && [[NSFileManager defaultManager] fileExistsAtPath:result]) {
    _nodePath = result;
    return _nodePath;
  }
  return nil;
}

// ── resolveEngineScriptPath ───────────────────────────────────────────────────

- (NSString *)resolveEngineScriptPath
{
  NSString *stored = [[NSUserDefaults standardUserDefaults] stringForKey:kEngineScriptKey];
  if (stored.length) return stored;

  // Check the engine/ subdirectory first — this is where bundle-engine.sh places
  // the script alongside node_modules/ for distribution builds.
  NSString *bundledInDir = [[NSBundle mainBundle] pathForResource:@"engine-server"
                                                           ofType:@"js"
                                                      inDirectory:@"engine"];
  if (bundledInDir && [self findNodeModulesForScript:bundledInDir]) return bundledInDir;

  // Flat bundle fallback (legacy single-file Xcode resource reference).
  NSString *bundled = [[NSBundle mainBundle] pathForResource:@"engine-server" ofType:@"js"];
  if (bundled && [self findNodeModulesForScript:bundled]) return bundled;

  return nil;
}

// ── startSidecar ──────────────────────────────────────────────────────────────
// Launches engine-server.js as an NSTask, reads the PORT: line from its stdout,
// and resolves with the port number. Rejects if node or the script can't be found,
// or if no port is reported within 15 seconds.

RCT_EXPORT_METHOD(startSidecar:(RCTPromiseResolveBlock)resolve
                        reject:(RCTPromiseRejectBlock)reject)
{
  if (_sidecarTask && [_sidecarTask isRunning]) {
    resolve(@(_sidecarPort));
    return;
  }

  NSString *nodePath = [self resolveNodeBinary];
  if (!nodePath) {
    reject(@"NODE_NOT_FOUND",
           @"Could not locate the node binary. Make sure Node.js is installed and on your PATH.",
           nil);
    return;
  }

  NSString *scriptPath = [self resolveEngineScriptPath];
  if (!scriptPath) {
    reject(@"SCRIPT_NOT_FOUND",
           @"engine-server.js path not configured. Call pickEngineScript first.",
           nil);
    return;
  }

  NSString *configPath = [self resolvedConfigPath];

  NSTask *task = [NSTask new];
  task.launchPath = nodePath;
  task.arguments = @[scriptPath, configPath];

  // Ensure node can find workspace packages (e.g. @timesheets/engine) by
  // setting NODE_PATH to the nearest node_modules that contains them.
  NSString *nodeModules = [self findNodeModulesForScript:scriptPath];
  if (nodeModules) {
    NSMutableDictionary *env = [NSProcessInfo.processInfo.environment mutableCopy];
    NSString *existing = env[@"NODE_PATH"];
    env[@"NODE_PATH"] = existing.length
      ? [NSString stringWithFormat:@"%@:%@", nodeModules, existing]
      : nodeModules;
    task.environment = env;
  }

  NSPipe *outPipe = [NSPipe pipe];
  NSPipe *errPipe = [NSPipe pipe];
  task.standardOutput = outPipe;
  task.standardError = errPipe;

  @try { [task launch]; }
  @catch (NSException *e) {
    reject(@"LAUNCH_ERROR", e.reason, nil);
    return;
  }

  _sidecarTask = task;
  _sidecarPort = -1;

  // Read PORT: from stdout using select() + read() with a 15-second deadline.
  int fd = outPipe.fileHandleForReading.fileDescriptor;

  dispatch_async(dispatch_get_global_queue(DISPATCH_QUEUE_PRIORITY_DEFAULT, 0), ^{
    NSDate *deadline = [NSDate dateWithTimeIntervalSinceNow:15.0];
    char buf[512];
    char accum[1024] = {0};
    size_t accumLen = 0;
    int foundPort = -1;

    while (foundPort < 0 && [NSDate.date compare:deadline] == NSOrderedAscending) {
      fd_set readfds;
      FD_ZERO(&readfds);
      FD_SET(fd, &readfds);
      struct timeval tv = { 0, 100000 }; // 100 ms
      int sel = select(fd + 1, &readfds, NULL, NULL, &tv);

      if (sel > 0) {
        ssize_t n = read(fd, buf, sizeof(buf) - 1);
        if (n <= 0) break; // EOF — process exited and closed stdout

        // Append to accumulator (cap at buffer size).
        size_t space = sizeof(accum) - accumLen - 1;
        if (space > 0) {
          size_t copy = (size_t)n < space ? (size_t)n : space;
          memcpy(accum + accumLen, buf, copy);
          accumLen += copy;
          accum[accumLen] = '\0';
        }

        char *portTag = strstr(accum, "PORT:");
        if (portTag) {
          foundPort = atoi(portTag + 5);
        }
      }

      // Check after the read so we drain stdout before giving up.
      if (![self->_sidecarTask isRunning]) break;
    }

    if (foundPort > 0) {
      self->_sidecarPort = foundPort;
      resolve(@(foundPort));
    } else {
      // Collect stderr — this is the actual crash reason when node exits early.
      NSData *errData = [errPipe.fileHandleForReading availableData];
      NSString *errText = [[NSString alloc] initWithData:errData encoding:NSUTF8StringEncoding];
      errText = [errText stringByTrimmingCharactersInSet:[NSCharacterSet whitespaceAndNewlineCharacterSet]];

      [self->_sidecarTask terminate];
      self->_sidecarTask = nil;

      NSString *reason = errText.length
        ? [NSString stringWithFormat:@"Engine server failed to start:\n%@", errText]
        : @"Engine server did not report a port within 15 seconds";
      reject(@"SIDECAR_TIMEOUT", reason, nil);
    }
  });
}

// ── stopSidecar ───────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(stopSidecar:(RCTPromiseResolveBlock)resolve
                       reject:(RCTPromiseRejectBlock)reject)
{
  if (_sidecarTask) {
    [_sidecarTask terminate];
    _sidecarTask = nil;
    _sidecarPort = -1;
  }
  resolve(@YES);
}

// ── getSidecarPort ────────────────────────────────────────────────────────────
// Returns the port the sidecar is listening on, or -1 if not running.

RCT_EXPORT_METHOD(getSidecarPort:(RCTPromiseResolveBlock)resolve
                          reject:(RCTPromiseRejectBlock)reject)
{
  BOOL running = _sidecarTask && [_sidecarTask isRunning];
  resolve(@(running ? _sidecarPort : -1));
}

// ── requiresMainQueueSetup ────────────────────────────────────────────────────

+ (BOOL)requiresMainQueueSetup { return NO; }

@end
