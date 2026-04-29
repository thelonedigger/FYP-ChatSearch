export const formatters = {
  fileSize: (b: number): string => b === 0 ? '0 B' : b < 1024 ? `${b} B` : b < 1048576 ? `${(b / 1024).toFixed(1)} KB` : `${(b / 1048576).toFixed(1)} MB`,
  percentage: (s: number | null | undefined, d = 1): string => s == null || isNaN(s) ? 'N/A' : `${(s * 100).toFixed(d)}%`,
  relativeTime: (date: string | Date): string => {
    const ms = Date.now() - new Date(date).getTime()
    const [m, h, d] = [Math.floor(ms / 60000), Math.floor(ms / 3600000), Math.floor(ms / 86400000)]
    return m < 1 ? 'just now' : m < 60 ? `${m}m ago` : h < 24 ? `${h}h ago` : d < 7 ? `${d}d ago`
      : new Date(date).toLocaleDateString('en-GB', { timeZone: 'UTC' })
  },
  dateTime: (date: string | Date): string =>
  `${new Date(date).toLocaleString('en-GB', { timeZone: 'UTC' })} UTC`,
  compactNumber: (n: number): string => n < 1000 ? n.toString() : n < 1000000 ? `${(n / 1000).toFixed(1)}K` : `${(n / 1000000).toFixed(1)}M`,
  truncate: (t: string, max: number): string => t.length <= max ? t : t.substring(0, max - 3) + '...',
  intentLabel: (i: string): string => i.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' '),
}