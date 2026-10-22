#!/usr/bin/env python3
"""Gera bg-datacenter.svg: corredor de datacenter em perspectiva (racks, LEDs, piso).

Rodar de novo só se quiser mudar o desenho; a semente fixa mantém o resultado estável.
"""
import random
from pathlib import Path

W, H = 1600, 900
VPX, VPY = 800, 430          # ponto de fuga
TOP, BOTTOM = 40, 900        # alturas no plano mais próximo
random.seed(360)


def proj(side, z):
    """Coordenadas (x, y_topo, y_base) da parede `side` (-1 esq., +1 dir.) na profundidade z."""
    k = 1 / z
    x = VPX + side * VPX * k
    return x, VPY - (VPY - TOP) * k, VPY + (BOTTOM - VPY) * k


def lerp(a, b, t):
    return a + (b - a) * t


out = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" preserveAspectRatio="xMidYMid slice">',
       '<defs>',
       '<linearGradient id="rack" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#16263f"/><stop offset="1" stop-color="#0a1322"/></linearGradient>',
       '<radialGradient id="far" cx="50%" cy="50%" r="50%"><stop offset="0" stop-color="#22d3ee" stop-opacity=".55"/><stop offset="1" stop-color="#22d3ee" stop-opacity="0"/></radialGradient>',
       '<style>.l{animation:b 2.6s infinite}.l2{animation:b 1.3s infinite}.l3{animation:b 4.1s infinite}'
       '@keyframes b{0%,100%{opacity:1}50%{opacity:.15}}'
       '@media (prefers-reduced-motion:reduce){.l,.l2,.l3{animation:none}}</style>',
       '</defs>',
       f'<rect width="{W}" height="{H}" fill="#050912"/>']

# brilho no fundo do corredor
out.append(f'<ellipse cx="{VPX}" cy="{VPY}" rx="260" ry="170" fill="url(#far)"/>')

# piso: linhas para o ponto de fuga e linhas de profundidade
for i in range(-12, 13):
    out.append(f'<line x1="{VPX + i * 140}" y1="{H}" x2="{VPX}" y2="{VPY}" stroke="#22d3ee" stroke-opacity=".10"/>')
for z in [1.0, 1.25, 1.6, 2.1, 2.8, 3.8, 5.2, 7.2]:
    y = VPY + (BOTTOM - VPY) / z
    out.append(f'<line x1="0" y1="{y:.1f}" x2="{W}" y2="{y:.1f}" stroke="#22d3ee" stroke-opacity=".08"/>')

# eletrocalhas no teto
for off in (-170, 170):
    out.append(f'<line x1="{VPX + off * 2.2:.1f}" y1="0" x2="{VPX + off / 9:.1f}" y2="{VPY - (VPY - TOP) / 9:.1f}" stroke="#3987e5" stroke-opacity=".25" stroke-width="3"/>')

leds = ['#22d3ee', '#22d3ee', '#0ca30c', '#0ca30c', '#3987e5', '#fab219']
classes = ['l', 'l2', 'l3']
depths = [1.0 + i * 0.85 for i in range(10)]
for side in (-1, 1):
    for a, b in zip(depths, depths[1:]):
        zn, zf = a, b - 0.08                      # vão entre racks
        xn, tn, bn = proj(side, zn)
        xf, tf, bf = proj(side, zf)
        out.append(f'<polygon points="{xn:.1f},{tn:.1f} {xf:.1f},{tf:.1f} {xf:.1f},{bf:.1f} {xn:.1f},{bn:.1f}" '
                   f'fill="url(#rack)" stroke="#22d3ee" stroke-opacity=".35" stroke-width="{1.6 / zn:.2f}"/>')
        units = 14
        for u in range(1, units):
            t = u / units
            y1, y2 = lerp(tn, bn, t), lerp(tf, bf, t)
            out.append(f'<line x1="{xn:.1f}" y1="{y1:.1f}" x2="{xf:.1f}" y2="{y2:.1f}" stroke="#22d3ee" stroke-opacity=".12" stroke-width="{1 / zn:.2f}"/>')
            # LEDs perto da borda frontal de cada servidor
            for slot in (0.08, 0.14, 0.2):
                if random.random() < 0.55:
                    tc = t - 0.5 / units
                    x = lerp(xn, xf, slot)
                    y = lerp(lerp(tn, bn, tc), lerp(tf, bf, tc), slot)
                    r = max(0.6, 3.2 / lerp(zn, zf, slot))
                    out.append(f'<circle class="{random.choice(classes)}" style="animation-delay:{random.random() * 4:.2f}s" '
                               f'cx="{x:.1f}" cy="{y:.1f}" r="{r:.2f}" fill="{random.choice(leds)}"/>')

out.append('</svg>')
dest = Path(__file__).resolve().parent.parent / 'bg-datacenter.svg'
dest.write_text('\n'.join(out))
print(f'{dest} ({dest.stat().st_size // 1024} KB)')
