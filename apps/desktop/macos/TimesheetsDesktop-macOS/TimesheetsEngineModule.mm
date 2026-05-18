#import "TimesheetsEngineModule.h"
#import <React/RCTLog.h>

// NSUserDefaults key for the user-chosen config directory.
static NSString *const kConfigDirKey = @"TimesheetsConfigDir";

// Default config path when no directory has been chosen: ~/.config/timesheets/config.json
static NSString *defaultConfigPath(void)
{
  NSString *home = NSHomeDirectory();
  return [home stringByAppendingPathComponent:@".config/timesheets/config.json"];
}

@implementation TimesheetsEngineModule

RCT_EXPORT_MODULE(TimesheetsEngine);

- (NSString *)resolvedConfigPath
{
  NSString *dir = [[NSUserDefaults standardUserDefaults] stringForKey:kConfigDirKey];
  if (dir.length) {
    return [dir stringByAppendingPathComponent:@"config.json"];
  }
  return defaultConfigPath();
}

// ── getConfigPath ─────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(getConfigPath:(RCTPromiseResolveBlock)resolve
                      reject:(RCTPromiseRejectBlock)reject)
{
  resolve([self resolvedConfigPath]);
}

// ── setConfigDir ──────────────────────────────────────────────────────────────
// Called from JS after the user picks a directory via a file-open panel.

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

  if (![fm fileExistsAtPath:path]) {
    // Return null so JS can detect a missing config and prompt the user.
    resolve([NSNull null]);
    return;
  }

  NSError *readErr;
  NSData *data = [NSData dataWithContentsOfFile:path options:0 error:&readErr];
  if (readErr) {
    reject(@"READ_ERROR", readErr.localizedDescription, readErr);
    return;
  }

  NSError *jsonErr;
  id parsed = [NSJSONSerialization JSONObjectWithData:data
                                             options:NSJSONReadingMutableContainers
                                               error:&jsonErr];
  if (jsonErr) {
    reject(@"PARSE_ERROR", jsonErr.localizedDescription, jsonErr);
    return;
  }

  resolve(parsed);
}

// ── saveConfig ────────────────────────────────────────────────────────────────

RCT_EXPORT_METHOD(saveConfig:(NSDictionary *)config
                    resolve:(RCTPromiseResolveBlock)resolve
                     reject:(RCTPromiseRejectBlock)reject)
{
  NSString *path = [self resolvedConfigPath];
  NSFileManager *fm = [NSFileManager defaultManager];

  // Ensure the parent directory exists.
  NSString *dir = [path stringByDeletingLastPathComponent];
  NSError *dirErr;
  if (![fm createDirectoryAtPath:dir withIntermediateDirectories:YES attributes:nil error:&dirErr]) {
    reject(@"DIR_ERROR", dirErr.localizedDescription, dirErr);
    return;
  }

  // Write a dated backup alongside the config before overwriting.
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
  if (jsonErr) {
    reject(@"SERIALIZE_ERROR", jsonErr.localizedDescription, jsonErr);
    return;
  }

  // Append trailing newline to match PHP behaviour.
  NSMutableData *out = [data mutableCopy];
  [out appendBytes:"\n" length:1];

  NSError *writeErr;
  if (![out writeToFile:path options:NSDataWritingAtomic error:&writeErr]) {
    reject(@"WRITE_ERROR", writeErr.localizedDescription, writeErr);
    return;
  }

  resolve(path);
}

// ── pickConfigDir ─────────────────────────────────────────────────────────────
// Opens a native NSOpenPanel so the user can locate their config directory.
// Must run on the main thread.

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
      resolve([NSNull null]); // user cancelled
    }
  });
}

// Bridge requires +requiresMainQueueSetup.
+ (BOOL)requiresMainQueueSetup
{
  return NO;
}

@end
