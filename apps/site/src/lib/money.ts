/**
 * Formato de montos para mostrar. El monto llega como texto decimal exacto de la API ({amount, currency}); aquí
 * solo se agrupan los miles y se pone el símbolo: el navegador no calcula precios ni impuestos (regla 11).
 */
export type Money = { amount: string; currency: string }

const SYMBOLS: Record<string, string> = { PEN: 'S/', USD: 'US$', EUR: '€' }
const SEPARATORS: Record<string, { group: string; decimal: string }> = {
  es: { group: ' ', decimal: '.' },
  en: { group: ',', decimal: '.' },
  pt: { group: '.', decimal: ',' },
}

export function formatMoney(money: Money, lang: string): string {
  const sep = SEPARATORS[lang] ?? SEPARATORS.es
  const negative = money.amount.startsWith('-')
  const [intPart, decPart = ''] = money.amount.replace('-', '').split('.')
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, sep.group)
  const decimals = decPart === '' || /^0+$/.test(decPart) ? '' : `${sep.decimal}${decPart.padEnd(2, '0').slice(0, 2)}`
  const symbol = SYMBOLS[money.currency] ?? money.currency
  return `${negative ? '-' : ''}${symbol} ${grouped}${decimals}`
}
