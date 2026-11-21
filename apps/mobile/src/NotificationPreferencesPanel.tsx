import { useEffect, useState } from 'react'
import { ActivityIndicator, Platform, Pressable, StyleSheet, Switch, Text, View } from 'react-native'
import { ApiError, mobileApi } from './api'
import * as Notifications from 'expo-notifications'
import * as Device from 'expo-device'
import Constants from 'expo-constants'
import * as SecureStore from 'expo-secure-store'
import { mobilePushTokenKey } from './pushRegistration'

type Category = 'announcements' | 'meetings' | 'fees' | 'learning'
type Preferences = {
  announcements: boolean
  meetings: boolean
  fees: boolean
  learning: boolean
  email: Record<Category, boolean>
}
type Delivery = {
  id: number
  title: string
  status: 'queued' | 'sent' | 'failed' | 'cancelled'
  attempts: number
  queued_at: string
  sent_at: string | null
  failed_at: string | null
  cancelled_at: string | null
}

const defaults: Preferences = {
  announcements: true,
  meetings: true,
  fees: true,
  learning: true,
  email: { announcements: false, meetings: false, fees: false, learning: false },
}
const categories: { key: Category; title: string; description: string }[] = [
  { key: 'announcements', title: 'Announcements', description: 'Updates shared by your organization.' },
  { key: 'meetings', title: 'Scheduled meetings', description: 'Changes to classes and meeting schedules.' },
  { key: 'fees', title: 'Fee updates', description: 'New fee records and receipt confirmations.' },
  { key: 'learning', title: 'Learning updates', description: 'New lessons, assignments, and quizzes in your courses.' },
]

export function NotificationPreferencesPanel({ token, organizationId, userId }: { token: string; organizationId: number; userId: number }) {
  const [preferences, setPreferences] = useState<Preferences>(defaults)
  const [emailAvailable, setEmailAvailable] = useState(false)
  const [pushAvailable, setPushAvailable] = useState(false)
  const [pushEnabled, setPushEnabled] = useState(false)
  const [pushBusy, setPushBusy] = useState(false)
  const [deliveries, setDeliveries] = useState<Delivery[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [saved, setSaved] = useState(false)
  const [refresh, setRefresh] = useState(0)

  useEffect(() => {
    let active = true
    setLoading(true)
    setError('')
    Promise.all([
      mobileApi.get<{ data: Preferences; email_available: boolean; push_available: boolean }>('/api/v1/me/notification-preferences', token),
      mobileApi.get<{ data: Delivery[] }>(`/api/v1/organizations/${organizationId}/email-deliveries?per_page=10`, token),
      SecureStore.getItemAsync(mobilePushTokenKey(userId)),
    ]).then(([preferenceResult, deliveryResult, registeredToken]) => {
      if (!active) return
      setPreferences(preferenceResult.data)
      setEmailAvailable(preferenceResult.email_available)
      setPushAvailable(preferenceResult.push_available)
      setPushEnabled(Boolean(registeredToken))
      setDeliveries(deliveryResult.data)
    }).catch((reason: unknown) => {
      if (active) setError(reason instanceof ApiError ? reason.message : 'Could not load notification settings.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [organizationId, refresh, token, userId])

  async function setPushRegistration(enabled: boolean): Promise<void> {
    if (pushBusy) return
    setPushBusy(true)
    setError('')
    try {
      if (!enabled) {
        const currentToken = await SecureStore.getItemAsync(mobilePushTokenKey(userId))
        if (currentToken) await mobileApi.delete('/api/v1/me/push-devices', token, { token: currentToken })
        await SecureStore.deleteItemAsync(mobilePushTokenKey(userId))
        setPushEnabled(false)
        setSaved(true)
        return
      }

      if (!['ios', 'android'].includes(Platform.OS) || !Device.isDevice) throw new Error('Push alerts need an installed Android or iOS app on a physical device.')
      const projectId = Constants.easConfig?.projectId ?? Constants.expoConfig?.extra?.eas?.projectId
      if (!projectId) throw new Error('Push registration needs an EAS project ID. Set EXPO_PUBLIC_EAS_PROJECT_ID and rebuild the mobile app.')

      let permission = await Notifications.getPermissionsAsync()
      if (!permission.granted) permission = await Notifications.requestPermissionsAsync()
      if (!permission.granted) throw new Error('Notification permission is off. Enable notifications for edTechLive in your device settings, then try again.')

      if (Platform.OS === 'android') {
        await Notifications.setNotificationChannelAsync('default', { name: 'edTechLive updates', importance: Notifications.AndroidImportance.DEFAULT })
      }
      const pushToken = (await Notifications.getExpoPushTokenAsync({ projectId })).data
      await mobileApi.post('/api/v1/me/push-devices', token, { token: pushToken, platform: Platform.OS })
      await SecureStore.setItemAsync(mobilePushTokenKey(userId), pushToken)
      setPushEnabled(true)
      setSaved(true)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : reason instanceof Error ? reason.message : 'Could not update push notification settings.')
    } finally {
      setPushBusy(false)
    }
  }

  async function save(next: Preferences): Promise<void> {
    if (saving) return
    const previous = preferences
    const payload = emailAvailable ? next : { ...next, email: { announcements: false, meetings: false, fees: false, learning: false } }
    setPreferences(payload)
    setSaving(true)
    setError('')
    setSaved(false)
    try {
      const result = await mobileApi.patch<{ data: Preferences; email_available: boolean }>('/api/v1/me/notification-preferences', token, payload)
      setPreferences(result.data)
      setEmailAvailable(result.email_available)
      setSaved(true)
    } catch (reason) {
      setPreferences(previous)
      setError(reason instanceof ApiError ? reason.message : 'Could not save notification settings.')
    } finally {
      setSaving(false)
    }
  }

  return <View style={styles.panel}>
    <Text style={styles.title} accessibilityRole="header">Notification preferences</Text>
    <Text style={styles.description}>Choose which updates appear in your account inbox. Your choices are saved across devices.</Text>
    {loading ? <View style={styles.status}><ActivityIndicator size="small" color="#315d9c" /><Text style={styles.statusText}>Loading notification settings…</Text></View> : error ? <View accessibilityRole="alert"><Text style={styles.error}>{error}</Text><Pressable onPress={() => setRefresh(value => value + 1)} accessibilityRole="button"><Text style={styles.action}>Try again</Text></Pressable></View> : <>
      <Text style={styles.groupTitle}>In-app notices</Text>
      {categories.map(category => <View style={styles.row} key={`in-app-${category.key}`}>
        <View style={styles.rowText}><Text style={styles.rowTitle}>{category.title}</Text><Text style={styles.rowDescription}>{category.description}</Text></View>
        <Switch value={preferences[category.key]} onValueChange={enabled => void save({ ...preferences, [category.key]: enabled })} disabled={saving} accessibilityLabel={`In-app ${category.title}`} trackColor={{ false: '#c9d2dd', true: '#8aa8d4' }} thumbColor={preferences[category.key] ? '#315d9c' : '#f7f9fc'} />
      </View>)}
      <Text style={styles.groupTitle}>Email alerts</Text>
      {emailAvailable ? categories.map(category => <View style={styles.row} key={`email-${category.key}`}>
        <View style={styles.rowText}><Text style={styles.rowTitle}>{category.title}</Text><Text style={styles.rowDescription}>{category.description}</Text></View>
        <Switch value={preferences.email[category.key]} onValueChange={enabled => void save({ ...preferences, email: { ...preferences.email, [category.key]: enabled } })} disabled={saving} accessibilityLabel={`Email ${category.title}`} trackColor={{ false: '#c9d2dd', true: '#8aa8d4' }} thumbColor={preferences.email[category.key] ? '#315d9c' : '#f7f9fc'} />
      </View>) : <Text style={styles.unavailable}>Email delivery is disabled until an administrator configures an approved mail transport and queue worker. In-app notices continue to work.</Text>}
      {saving ? <View style={styles.status}><ActivityIndicator size="small" color="#315d9c" /><Text style={styles.statusText}>Saving…</Text></View> : saved ? <View accessibilityLiveRegion="polite"><Text style={styles.saved}>Preferences saved.</Text></View> : null}
      <Text style={styles.groupTitle}>Mobile push alerts</Text>
      {pushAvailable || pushEnabled ? <>
        <Text style={styles.description}>Push notices use the same category choices as your in-app inbox. Alert text stays private on your lock screen.</Text>
        <View style={styles.row}><View style={styles.rowText}><Text style={styles.rowTitle}>This device</Text><Text style={styles.rowDescription}>{pushEnabled ? 'Push alerts are enabled for this device.' : 'Enable alerts for updates on this device.'}</Text></View><Switch value={pushEnabled} onValueChange={enabled => void setPushRegistration(enabled)} disabled={pushBusy} accessibilityLabel="Push notifications on this device" trackColor={{ false: '#c9d2dd', true: '#8aa8d4' }} thumbColor={pushEnabled ? '#315d9c' : '#f7f9fc'} /></View>
        {pushBusy ? <View style={styles.status}><ActivityIndicator size="small" color="#315d9c" /><Text style={styles.statusText}>Updating device registration…</Text></View> : null}
      </> : <Text style={styles.unavailable}>Push delivery is disabled until the API and Android/iOS app are configured for the same Expo project.</Text>}
      <Text style={styles.groupTitle}>Recent email delivery</Text>
      <Text style={styles.description}>Status only. Message content and recipient details are not shown here.</Text>
      {deliveries.length ? deliveries.map(delivery => <View style={styles.delivery} key={delivery.id}>
        <View style={styles.rowText}><Text style={styles.rowTitle} numberOfLines={1}>{delivery.title}</Text><Text style={styles.rowDescription}>{delivery.status}{delivery.status === 'failed' ? ` after ${delivery.attempts} attempts` : ''}</Text></View>
        <Text style={styles.deliveryDate}>{new Date(delivery.sent_at ?? delivery.failed_at ?? delivery.cancelled_at ?? delivery.queued_at).toLocaleDateString()}</Text>
      </View>) : <Text style={styles.empty}>No email notifications have been queued for this workspace.</Text>}
    </>}
  </View>
}

const styles = StyleSheet.create({
  panel: { marginTop: 24, padding: 14, borderWidth: 1, borderColor: '#e8edf3', borderRadius: 12, backgroundColor: '#fff' },
  title: { color: '#19395f', fontSize: 17, fontWeight: '800' },
  description: { color: '#71839a', fontSize: 11, lineHeight: 17, marginTop: 5 },
  groupTitle: { color: '#425c77', fontSize: 12, fontWeight: '800', marginTop: 18, marginBottom: 5 },
  row: { minHeight: 58, flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12, paddingVertical: 8, borderTopWidth: 1, borderTopColor: '#edf1f5' },
  rowText: { flex: 1, minWidth: 0 },
  rowTitle: { color: '#405772', fontSize: 11, fontWeight: '700' },
  rowDescription: { color: '#8391a0', fontSize: 9, lineHeight: 14, marginTop: 3 },
  status: { flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 12 },
  statusText: { color: '#71839a', fontSize: 10 },
  error: { color: '#a74c43', fontSize: 10, lineHeight: 15, paddingVertical: 8 },
  action: { color: '#315d9c', fontSize: 11, fontWeight: '700', paddingVertical: 8 },
  saved: { color: '#4d8063', fontSize: 10, paddingVertical: 9 },
  unavailable: { color: '#796a4b', fontSize: 10, lineHeight: 16, padding: 10, borderRadius: 8, backgroundColor: '#fffaf0' },
  delivery: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 9, borderTopWidth: 1, borderTopColor: '#edf1f5' },
  deliveryDate: { color: '#8190a0', fontSize: 9 },
  empty: { color: '#8391a0', fontSize: 10, lineHeight: 15, paddingVertical: 10 },
})
