import React, {useState} from 'react';
import {StyleSheet, Text, Pressable, View} from 'react-native';
import {ConfigScreen, ReportScreen, Brand} from '@timesheets/ui';

type Screen = 'home' | 'config' | 'reports';

function App(): React.JSX.Element {
  const [screen, setScreen] = useState<Screen>('home');

  if (screen === 'config') {
    return (
      <View style={styles.container}>
        <View style={styles.navBar}>
          <Pressable onPress={() => setScreen('home')} style={styles.backBtn}>
            <Text style={styles.backBtnText}>← Home</Text>
          </Pressable>
        </View>
        <ConfigScreen />
      </View>
    );
  }

  if (screen === 'reports') {
    return (
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
    );
  }

  return (
    <View style={[styles.container, {backgroundColor: Brand.ink}]}>
      <View style={styles.content}>
        <Text style={[styles.title, {color: Brand.paper}]}>Timesheets</Text>
        <Text style={[styles.subtitle, {color: Brand.amber}]}>
          Desktop app is running.
        </Text>
        <View style={styles.buttons}>
          <Pressable
            onPress={() => setScreen('reports')}
            style={styles.primaryBtn}>
            <Text style={styles.primaryBtnText}>Open Reports</Text>
          </Pressable>
          <Pressable
            onPress={() => setScreen('config')}
            style={styles.secondaryBtn}>
            <Text style={styles.secondaryBtnText}>Open Config</Text>
          </Pressable>
        </View>
      </View>
    </View>
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
  subtitle: {
    fontSize: 16,
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
});

export default App;
