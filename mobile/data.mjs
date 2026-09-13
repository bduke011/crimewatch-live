export const API_ORIGIN = 'https://crimewatch.live';
const endpoints = new Set(['api.php','fbi-api.php','fbi-extra-api.php','jail-api.php']);
export function apiURL(input, base = 'https://localhost/') {
  const url = new URL(input, base);
  if (endpoints.has(url.pathname.replace(/^\//,'')) && ['localhost','crimewatch.live','127.0.0.1'].includes(url.hostname)) {
    return API_ORIGIN + url.pathname + url.search;
  }
  return url.href;
}
export function normalizeSaved(value) {
  if (!Array.isArray(value)) return [];
  return value.filter(r => r && typeof r.id === 'string' && typeof r.offense === 'string' && typeof r.date === 'string').slice(0,100);
}
export function toggleSaved(rows, record) {
  const existing = rows.some(r => r.id === record.id);
  return existing ? rows.filter(r => r.id !== record.id) : [{...record,savedAt:new Date().toISOString()},...rows].slice(0,100);
}
export function shareText(r) {
  return `${r.offense}\n${r.date} · ${r.agencyLabel || ''}\n${r.location || ''}\n\nPublished report, not a finding of guilt. Locations are approximate.\nhttps://crimewatch.live/`;
}
