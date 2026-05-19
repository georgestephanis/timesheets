import React, {useState} from 'react';
import {
  SafeAreaView,
  StyleSheet,
  Text,
  Pressable,
  View,
  useColorScheme,
} from 'react-native';
import {ConfigScreen, ReportScreen} from '@timesheets/ui';

type Screen = 'home' | 'config' | 'reports';

function App(): React.JSX.Element {
  const isDarkMode = useColorScheme() === 'dark';
  const [screen, setScreen] = useState<Screen>('home');

  if (screen === 'config') {
    return (
      <SafeAreaView style={styles.container}>
        <View style={styles.navBar}>
          <Pressable onPress={() => setScreen('home')} style={styles.backBtn}>
            <Text style={styles.backBtnText}>← Home</Text>
          </Pressable>
        </View>
        <ConfigScreen />
      </SafeAreaView>
    );
  }

  if (screen === 'reports') {
    return (
      <SafeAreaView style={styles.container}>
        <View style={styles.navBar}>
          <Pressable onPress={() => setScreen('home')} style={styles.backBtn}>
            <Text style={styles.backBtnText}>← Home</Text>
          </Pressable>
          <Text style={styles.navTitle}>Reports</Text>
          <View style={styles.navSpacer} />
        </View>
        <ReportScreen />
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView
      style={[
        styles.container,
        {backgroundColor: isDarkMode ? '#1a1a1a' : '#f5f5f5'},
      ]}>
      <View style={styles.content}>
        <Text style={[styles.title, {color: isDarkMode ? '#fff' : '#000'}]}>
          Timesheets
        </Text>
        <Text style={[styles.subtitle, {color: isDarkMode ? '#aaa' : '#666'}]}>
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
    </SafeAreaView>
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
    borderBottomColor: '#d0d0d0',
    backgroundColor: '#f5f5f5',
  },
  backBtn: {
    paddingHorizontal: 4,
    paddingVertical: 4,
  },
  backBtnText: {
    fontSize: 13,
    color: '#007AFF',
  },
  navTitle: {
    flex: 1,
    textAlign: 'center',
    fontSize: 14,
    fontWeight: '600',
    color: '#333',
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
    backgroundColor: '#007AFF',
    borderRadius: 8,
    alignItems: 'center',
  },
  primaryBtnText: {
    fontSize: 14,
    color: '#fff',
    fontWeight: '600',
  },
  secondaryBtn: {
    paddingVertical: 10,
    paddingHorizontal: 24,
    borderWidth: 1,
    borderColor: '#007AFF',
    borderRadius: 8,
    alignItems: 'center',
  },
  secondaryBtnText: {
    fontSize: 14,
    color: '#007AFF',
  },
});

export default App;
