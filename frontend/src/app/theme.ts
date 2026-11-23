import { alpha, createTheme } from '@mui/material/styles'

/**
 * Tema Material 3 rosado de TIARE (docs/DESIGN.md).
 * Primario frambuesa, secundario rosa viejo, terciario damasco (cierres próximos),
 * verde salvia para estados positivos y superficies "blush".
 */
export const tokens = {
  primary: '#984061',
  onPrimary: '#FFFFFF',
  primaryContainer: '#FFD9E2',
  onPrimaryContainer: '#3E001D',
  secondary: '#74565F',
  secondaryContainer: '#FFD9E2',
  onSecondaryContainer: '#2B151C',
  tertiary: '#7C5635',
  tertiaryContainer: '#FFDCC1',
  onTertiaryContainer: '#2E1500',
  success: '#3A6A4F',
  successContainer: '#BCEFCF',
  error: '#BA1A1A',
  errorContainer: '#FFDAD6',
  surface: '#FFF8F8',
  surfaceLow: '#FCF0F2',
  surfaceContainer: '#F6EAEC',
  surfaceHigh: '#F1E4E6',
  onSurface: '#22191C',
  onSurfaceVariant: '#524347',
  outline: '#837377',
  outlineVariant: '#D5C2C6',
  urgent: '#A04A12',
}

export const codeFont = "'Archivo', 'Roboto Condensed', system-ui, sans-serif"

export const theme = createTheme({
  palette: {
    mode: 'light',
    primary: { main: tokens.primary, light: '#B65A7B', dark: '#7B2949', contrastText: tokens.onPrimary },
    secondary: { main: tokens.secondary, contrastText: '#FFFFFF' },
    success: { main: tokens.success, contrastText: '#FFFFFF' },
    warning: { main: tokens.tertiary, contrastText: '#FFFFFF' },
    info: { main: '#5B5B9A', contrastText: '#FFFFFF' },
    error: { main: tokens.error },
    background: { default: tokens.surface, paper: '#FFFFFF' },
    text: { primary: tokens.onSurface, secondary: tokens.onSurfaceVariant },
    divider: tokens.outlineVariant,
  },
  shape: { borderRadius: 12 },
  typography: {
    fontFamily: "'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif",
    h1: { fontSize: '2rem', fontWeight: 700, letterSpacing: '-0.02em' },
    h2: { fontSize: '1.5rem', fontWeight: 700, letterSpacing: '-0.01em' },
    h3: { fontSize: '1.125rem', fontWeight: 700 },
    h4: { fontSize: '1rem', fontWeight: 700 },
    subtitle1: { fontWeight: 600 },
    subtitle2: { fontWeight: 600 },
    body1: { fontSize: '0.9375rem' },
    body2: { fontSize: '0.875rem' },
    button: { textTransform: 'none', fontWeight: 600, letterSpacing: 0 },
    caption: { fontSize: '0.75rem' },
  },
  components: {
    MuiCssBaseline: {
      styleOverrides: {
        body: { backgroundColor: tokens.surface },
        '.code': {
          fontFamily: codeFont,
          fontVariationSettings: "'wdth' 75",
          fontWeight: 650,
          fontVariantNumeric: 'tabular-nums',
          letterSpacing: '0.01em',
          whiteSpace: 'nowrap',
        },
        '.num': { fontFamily: codeFont, fontVariationSettings: "'wdth' 88", fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
      },
    },
    MuiButton: {
      defaultProps: { disableElevation: true },
      styleOverrides: {
        root: { borderRadius: 20, paddingInline: 18, minHeight: 38 },
        outlined: { borderColor: tokens.outline },
      },
    },
    MuiCard: {
      defaultProps: { variant: 'outlined' },
      styleOverrides: { root: { borderRadius: 16, borderColor: tokens.outlineVariant } },
    },
    MuiPaper: { styleOverrides: { rounded: { borderRadius: 16 } } },
    MuiChip: {
      styleOverrides: {
        root: { borderRadius: 8, fontWeight: 600 },
        sizeSmall: { height: 24, fontSize: '0.75rem' },
      },
    },
    MuiTextField: { defaultProps: { size: 'small' } },
    MuiSelect: { defaultProps: { size: 'small' } },
    MuiOutlinedInput: {
      styleOverrides: {
        root: { borderRadius: 10, backgroundColor: '#FFFFFF' },
        notchedOutline: { borderColor: tokens.outlineVariant },
      },
    },
    MuiTabs: { styleOverrides: { indicator: { height: 3, borderRadius: '3px 3px 0 0' } } },
    MuiTab: { styleOverrides: { root: { textTransform: 'none', fontWeight: 600, minHeight: 52 } } },
    MuiTableCell: {
      styleOverrides: {
        head: { fontWeight: 600, color: tokens.onSurfaceVariant, backgroundColor: tokens.surfaceLow },
        root: { borderColor: alpha(tokens.outlineVariant, 0.7) },
      },
    },
    MuiListItemButton: {
      styleOverrides: {
        root: {
          borderRadius: 28,
          marginInline: 8,
          '&.active': { backgroundColor: tokens.primaryContainer, color: tokens.onPrimaryContainer, fontWeight: 700 },
          '&.active .MuiListItemIcon-root': { color: tokens.onPrimaryContainer },
        },
      },
    },
    MuiDrawer: { styleOverrides: { paper: { borderColor: tokens.outlineVariant } } },
    MuiAlert: { styleOverrides: { root: { borderRadius: 12 } } },
    MuiTooltip: { styleOverrides: { tooltip: { backgroundColor: tokens.onSurface, fontSize: '0.75rem' } } },
  },
})
