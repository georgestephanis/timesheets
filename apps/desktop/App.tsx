import React, {useState} from 'react';
import {
  StyleSheet,
  Text,
  Pressable,
  View,
  ActivityIndicator,
} from 'react-native';
import {
  ConfigScreen,
  ReportScreen,
  Brand,
  SidecarProvider,
  useSidecar,
} from '@timesheets/ui';

type Screen = 'home' | 'config' | 'reports';

function HomeScreen({onNavigate}: {onNavigate: (s: Screen) => void}) {
  const {
    state: sidecarState,
    error: sidecarError,
    locateAndStart,
  } = useSidecar();

  const statusLine = (() => {
    switch (sidecarState) {
      case 'idle':
      case 'starting':
        return {text: 'Starting engine…', color: Brand.amber, spinner: true};
      case 'running':
        return null; // no status needed when everything is good
      case 'no-script':
        return {
          text: 'Engine not configured',
          color: Brand.amber,
          spinner: false,
        };
      case 'error':
        return {
          text: `Engine error: ${sidecarError}`,
          color: '#e05040',
          spinner: false,
        };
    }
  })();

  return (
    <View style={[styles.container, {backgroundColor: Brand.ink}]}>
      <View style={styles.content}>
        <Text style={[styles.title, {color: Brand.paper}]}>Timesheets</Text>

        {statusLine && (
          <View style={styles.statusRow}>
            {statusLine.spinner && (
              <ActivityIndicator
                size="small"
                color={Brand.amber}
                style={styles.spinner}
              />
            )}
            <Text style={[styles.statusText, {color: statusLine.color}]}>
              {statusLine.text}
            </Text>
          </View>
        )}

        <View style={styles.buttons}>
          <Pressable
            onPress={() => onNavigate('reports')}
            style={styles.primaryBtn}>
            <Text style={styles.primaryBtnText}>Open Reports</Text>
          </Pressable>
          <Pressable
            onPress={() => onNavigate('config')}
            style={styles.secondaryBtn}>
            <Text style={styles.secondaryBtnText}>Open Config</Text>
          </Pressable>
          {sidecarState === 'no-script' && (
            <Pressable onPress={locateAndStart} style={styles.setupBtn}>
              <Text style={styles.setupBtnText}>Set Up Engine…</Text>
            </Pressable>
          )}
        </View>
      </View>
    </View>
  );
}

function App(): React.JSX.Element {
  const [screen, setScreen] = useState<Screen>('home');

  return (
    <SidecarProvider>
      {screen === 'config' ? (
        <View style={styles.container}>
          <View style={styles.navBar}>
            <Pressable onPress={() => setScreen('home')} style={styles.backBtn}>
              <Text style={styles.backBtnText}>← Home</Text>
            </Pressable>
          </View>
          <ConfigScreen />
        </View>
      ) : screen === 'reports' ? (
        <View style={styles.container}>
          <View style={styles.navBar}>
            <Pressable onPress={() => setScreen('home')} style={styles.backBtn}>
              <Text style={styles.backBtnText}>← Home</Text>
            </Pressable>
            <Text style={styles.navTitle}>Reports</Text>
            <View style={styles.navSpacer} />
          </View>
          <ReportScreen />
        </View>
      ) : (
        <HomeScreen onNavigate={setScreen} />
      )}
    </SidecarProvider>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
  },
  navBar: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#333',
    backgroundColor: Brand.ink,
  },
  backBtn: {
    paddingHorizontal: 4,
    paddingVertical: 4,
  },
  backBtnText: {
    fontSize: 13,
    color: Brand.paper,
  },
  navTitle: {
    flex: 1,
    textAlign: 'center',
    fontSize: 14,
    fontWeight: '600',
    color: Brand.paper,
  },
  navSpacer: {
    width: 60,
  },
  content: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: 16,
  },
  title: {
    fontSize: 32,
    fontWeight: '700',
  },
  statusRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
  },
  spinner: {
    marginRight: 2,
  },
  statusText: {
    fontSize: 13,
  },
  buttons: {
    gap: 10,
    alignItems: 'stretch',
    marginTop: 8,
  },
  primaryBtn: {
    paddingVertical: 10,
    paddingHorizontal: 24,
    backgroundColor: Brand.terracotta,
    borderRadius: 8,
    alignItems: 'center',
  },
  primaryBtnText: {
    fontSize: 14,
    color: Brand.paper,
    fontWeight: '600',
  },
  secondaryBtn: {
    paddingVertical: 10,
    paddingHorizontal: 24,
    borderWidth: 1,
    borderColor: Brand.paper,
    borderRadius: 8,
    alignItems: 'center',
  },
  secondaryBtnText: {
    fontSize: 14,
    color: Brand.paper,
  },
  setupBtn: {
    paddingVertical: 10,
    paddingHorizontal: 24,
    borderWidth: 1,
    borderColor: Brand.amber,
    borderRadius: 8,
    alignItems: 'center',
  },
  setupBtnText: {
    fontSize: 14,
    color: Brand.amber,
  },
});

export default App;
