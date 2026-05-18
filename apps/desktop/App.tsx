import React, {useState} from 'react';
import {
  SafeAreaView,
  StyleSheet,
  Text,
  Pressable,
  View,
  useColorScheme,
} from 'react-native';
import {ConfigScreen} from '@timesheets/ui';

type Screen = 'home' | 'config';

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
        <Pressable onPress={() => setScreen('config')} style={styles.configBtn}>
          <Text style={styles.configBtnText}>Open Config</Text>
        </Pressable>
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
  configBtn: {
    marginTop: 8,
    paddingVertical: 10,
    paddingHorizontal: 20,
    backgroundColor: '#007AFF',
    borderRadius: 8,
  },
  configBtnText: {
    fontSize: 14,
    color: '#fff',
    fontWeight: '600',
  },
});

export default App;
