import type { ExpoConfig } from 'expo/config'

export default ({ config }: { config: ExpoConfig }): ExpoConfig => {
  const projectId = process.env.EXPO_PUBLIC_EAS_PROJECT_ID || config.extra?.eas?.projectId

  return {
    ...config,
    extra: {
      ...config.extra,
      ...(projectId ? { eas: { ...config.extra?.eas, projectId } } : {}),
    },
  }
}
