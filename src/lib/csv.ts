type CsvValue = string | number | null | undefined

function escapeCsvValue(value: CsvValue) {
  let text = value == null ? '' : String(value)
  // Prevent spreadsheet applications from interpreting exported labels as formulas.
  const firstMeaningful = text.trimStart().charAt(0)
  if (firstMeaningful && '=+-@'.includes(firstMeaningful)) text = `'${text}`
  return `"${text.replaceAll('"', '""')}"`
}

export function downloadCsv(filename: string, headers: string[], rows: CsvValue[][]) {
  const content = [headers, ...rows].map(row => row.map(escapeCsvValue).join(',')).join('\r\n')
  const blob = new Blob([`\uFEFF${content}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.hidden = true
  document.body.append(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 0)
}
