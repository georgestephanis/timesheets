#import "AppDelegate.h"

#import <React/RCTBundleURLProvider.h>
#import <ReactAppDependencyProvider/RCTAppDependencyProvider.h>

@implementation AppDelegate

- (void)applicationDidFinishLaunching:(NSNotification *)notification
{
  self.moduleName = @"TimesheetsDesktop";
  // You can add your custom initial props in the dictionary below.
  // They will be passed down to the ViewController used by React Native.
  self.initialProps = @{};
  self.dependencyProvider = [RCTAppDependencyProvider new];

  return [super applicationDidFinishLaunching:notification];
}

- (NSURL *)sourceURLForBridge:(RCTBridge *)bridge
{
  return [self bundleURL];
}

- (NSURL *)bundleURL
{
#if DEBUG
  RCTBundleURLProvider *provider = [RCTBundleURLProvider sharedSettings];
  NSLog(@"[AppDelegate] bundleURL DEBUG=1");
  NSLog(@"[AppDelegate] jsLocation before: %@", provider.jsLocation ?: @"(nil)");
  NSLog(@"[AppDelegate] packagerServerHost before: %@", [provider packagerServerHost] ?: @"(nil)");
  NSLog(@"[AppDelegate] enableDev: %d", provider.enableDev);

  provider.jsLocation = @"localhost";
  NSLog(@"[AppDelegate] jsLocation after: %@", provider.jsLocation ?: @"(nil)");
  NSLog(@"[AppDelegate] packagerServerHost after: %@", [provider packagerServerHost] ?: @"(nil)");

  NSURL *url = [provider jsBundleURLForBundleRoot:@"index"];
  NSLog(@"[AppDelegate] jsBundleURLForBundleRoot result: %@", url ?: @"(nil — will crash)");
  return url;
#else
  return [[NSBundle mainBundle] URLForResource:@"main" withExtension:@"jsbundle"];
#endif
}

/// This method controls whether the `concurrentRoot`feature of React18 is turned on or off.
///
/// @see: https://reactjs.org/blog/2022/03/29/react-v18.html
/// @note: This requires to be rendering on Fabric (i.e. on the New Architecture).
/// @return: `true` if the `concurrentRoot` feature is enabled. Otherwise, it returns `false`.
- (BOOL)concurrentRootEnabled
{
#ifdef RN_FABRIC_ENABLED
  return true;
#else
  return false;
#endif
}

@end
