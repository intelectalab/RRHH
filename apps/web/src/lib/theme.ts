export type ThemeChoice = 'light' | 'dark' | 'system'

export const THEME_KEY = 'ayni.theme'

/**
 * Script que corre antes de pintar: aplica el tema elegido o el del sistema para evitar
 * el destello de tema equivocado. Se inyecta tal cual en <head>.
 */
export const themeBootScript = `(function(){try{var c=localStorage.getItem('${THEME_KEY}')||'system';var d=c==='dark'||(c==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');}catch(e){}})();`

export function applyTheme(choice: ThemeChoice): void {
  const dark = choice === 'dark' || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light')
  try {
    if (choice === 'system') window.localStorage.removeItem(THEME_KEY)
    else window.localStorage.setItem(THEME_KEY, choice)
  } catch {
    // sin almacenamiento: el tema dura mientras la página esté abierta
  }
}

export function storedTheme(): ThemeChoice {
  try {
    const v = window.localStorage.getItem(THEME_KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch {
    return 'system'
  }
}
