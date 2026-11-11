# -*- coding: utf-8 -*-
"""Marca de GestiaHR: una sola fuente para el logo del sitio (build.py), de las imágenes (gen_og.py) y del prototipo.
"""
# Logo provisional de GestiaHR (v1.12): una «G» cuyo trazo rodea un punto amarillo, la persona al centro.
# Azul #0A3A73 (marca), amarillo #F4B400 (estado activo) y verde #0E7C6B para «HR» en el nombre.
G_PATHS = '<path d="M21.66 10.34A8 8 0 1 0 24 16h-5" fill="none" stroke="{g}" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/><circle cx="14.4" cy="16" r="2.2" fill="{p}"/>'
def g_mark(fondo='#0A3A73', g='#FFFFFF', p='#F4B400', rx=8):
    return f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" aria-hidden="true" focusable="false"><rect width="32" height="32" rx="{rx}" fill="{fondo}"/>' + G_PATHS.format(g=g, p=p) + '</svg>'
def logo_svg(oscuro=False):
    """Logo horizontal para archivos (assets/img/logo-gestiahr.svg y su versión para fondo oscuro)."""
    tx, hr = ('#FFFFFF', '#5FD1BE') if oscuro else ('#0A3A73', '#0E7C6B')
    return ('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 172 40" role="img" aria-label="GestiaHR">'
            '<g transform="translate(0 4)">' + g_mark()[g_mark().index('<rect'):-6] + '</g>'
            f'<text x="42" y="28" font-family="IBM Plex Sans, Arial, sans-serif" font-size="22" font-weight="700" fill="{tx}">Gestia<tspan fill="{hr}">HR</tspan></text></svg>')

