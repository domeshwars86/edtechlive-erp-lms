type CsvValue = string | number | null | undefined

export function parseCsv(text: string): string[][] {
  const input = text.replace(/^\uFEFF/, '')
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let quoted = false
  let closedQuote = false

  for (let index = 0; index < input.length; index += 1) {
    const character = input[index]
    if (quoted) {
      if (character === '"' && input[index + 1] === '"') {
        field += '"'
        index += 1
      } else if (character === '"') {
        quoted = false
        closedQuote = true
      } else {
        field += character
      }
    } else if (closedQuote && (character === ',' || character === '\n' || character === '\r')) {
      row.push(field)
      field = ''
      closedQuote = false
      if (character === '\r' || character === '\n') {
        if (character === '\r' && input[index + 1] === '\n') index += 1
        if (row.some(value => value.trim() !== '')) rows.push(row)
        row = []
      }
    } else if (closedQuote && (character === ' ' || character === '\t')) {
      // Whitespace after a closing quote is ignored before the next delimiter.
    } else if (closedQuote) {
      throw new Error('Unexpected text after a quoted CSV field.')
    } else if (character === '"') {
      if (field.length > 0) throw new Error('A CSV quote appears inside an unquoted field.')
      quoted = true
    } else if (character === ',') {
      row.push(field)
      field = ''
    } else if (character === '\n' || character === '\r') {
      if (character === '\r' && input[index + 1] === '\n') index += 1
      row.push(field)
      if (row.some(value => value.trim() !== '')) rows.push(row)
      row = []
      field = ''
      closedQuote = false
    } else {
      field += character
    }
  }

  if (quoted) throw new Error('The CSV contains an unclosed quoted field.')
  row.push(field)
  if (row.some(value => value.trim() !== '')) rows.push(row)
  return rows
}

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
