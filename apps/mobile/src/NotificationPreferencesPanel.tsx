import { useEffect, useState } from 'react'
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, View } from 'react-native'
import { ApiError, mobileApi } from './api'

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

export function NotificationPreferencesPanel({ token, organizationId }: { token: string; organizationId: number }) {
  const [preferences, setPreferences] = useState<Preferences>(defaults)
  const [emailAvailable, setEmailAvailable] = useState(false)
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
      mobileApi.get<{ data: Preferences; email_available: boolean }>('/api/v1/me/notification-preferences', token),
      mobileApi.get<{ data: Delivery[] }>(`/api/v1/organizations/${organizationId}/email-deliveries?per_page=10`, token),
    ]).then(([preferenceResult, deliveryResult]) => {
      if (!active) return
      setPreferences(preferenceResult.data)
      setEmailAvailable(preferenceResult.email_available)
      setDeliveries(deliveryResult.data)
    }).catch((reason: unknown) => {
      if (active) setError(reason instanceof ApiError ? reason.message : 'Could not load notification settings.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [organizationId, refresh, token])

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
