import { closingInfo, compraAgilStatusClass, formatAmount, formatDateTime, formatMoney, orderStatusClass, tenderStatusClass, timeUntil } from './format'
import { toQueryString } from '../services/apiClient'

describe('format', () => {
  it('formatea CLP sin decimales', () => {
    expect(formatMoney('1599000')).toMatch(/1\.599\.000/)
    expect(formatMoney(null)).toBe('—')
    expect(formatMoney('no-numero')).toBe('—')
  })

  it('muestra fechas en hora de Chile', () => {
    // 14:00 UTC = 11:00 en Santiago (verano, UTC-3)
    expect(formatDateTime('2026-11-16T14:00:00+00:00')).toContain('11:00')
    expect(formatDateTime(null)).toBe('—')
  })

  it('calcula tiempo restante', () => {
    const now = new Date('2026-11-14T12:00:00Z')
    expect(timeUntil('2026-11-14T15:00:00Z', now)).toBe('Cierra en 3 h')
    expect(timeUntil('2026-11-14T11:00:00Z', now)).toBe('Cerrado')
    expect(timeUntil('2026-11-18T12:00:00Z', now)).toBe('Cierra en 4 días')
  })

  it('mapea estados a clases del prototipo', () => {
    expect(compraAgilStatusClass('publicada')).toBe('approved')
    expect(compraAgilStatusClass('cancelada')).toBe('rejected')
    expect(compraAgilStatusClass('desconocido')).toBe('draft')
  })
})

describe('toQueryString', () => {
  it('omite vacíos y une arreglos con coma', () => {
    expect(toQueryString({ q: 'aseo', status: ['publicada', 'cerrada'], region: [], open_only: true, page: 2, x: undefined, y: false })).toBe(
      '?q=aseo&status=publicada%2Ccerrada&open_only=1&page=2',
    )
  })
})

describe('formatos V1', () => {
  it('closingInfo distingue cierres próximos, normales y vencidos', () => {
    const now = new Date('2026-11-14T12:00:00Z')
    expect(closingInfo('2026-11-14T18:00:00Z', now)).toEqual({ text: 'Cierra en 6 h', tone: 'soon' })
    expect(closingInfo('2026-11-17T12:00:00Z', now)?.tone).toBe('normal')
    expect(closingInfo('2026-11-14T11:00:00Z', now)).toEqual({ text: 'Cerrada', tone: 'closed' })
  })

  it('formatAmount usa la moneda de origen sin convertir', () => {
    expect(formatAmount('1190000.00', 'CLP')).toMatch(/1\.190\.000/)
    expect(formatAmount('150', 'UTM')).toBe('150 UTM')
    expect(formatAmount(null, 'CLP')).toBe('—')
  })

  it('clases de estado de licitaciones y OC', () => {
    expect(tenderStatusClass(5)).toBe('approved')
    expect(tenderStatusClass(18)).toBe('rejected')
    expect(orderStatusClass(12)).toBe('completed')
    expect(orderStatusClass(9)).toBe('rejected')
  })
})
