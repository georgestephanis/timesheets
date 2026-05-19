#import <React/RCTBridgeModule.h>

@interface TimesheetsEngineModule : NSObject <RCTBridgeModule>

/// Returns the resolved path to config.json, creating the default location if needed.
- (NSString *)resolvedConfigPath;

@end
