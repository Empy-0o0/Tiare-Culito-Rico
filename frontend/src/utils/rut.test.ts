import { formatRut, isValidRut } from './rut'

describe('rut', () => {
  it('valida con módulo 11 (incluye DV K y 0)', () => {
    expect(isValidRut('12.345.678-5')).toBe(true)
    expect(isValidRut('123456785')).toBe(true)
    expect(isValidRut('10000013-k')).toBe(true)
    expect(isValidRut('1000013-0')).toBe(true)
    expect(isValidRut('12.345.678-9')).toBe(false)
    expect(isValidRut('abc')).toBe(false)
  })

  it('formatea con puntos y guion', () => {
    expect(formatRut('123456785')).toBe('12.345.678-5')
    expect(formatRut('10000013k')).toBe('10.000.013-K')
  })
})
