#!/usr/bin/env python3
"""
Generate placeholder PNG icons for the Chrome Extension.
Run once: python3 build-icons.py
Requires: pip install Pillow
"""

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    print("Pillow not installed. Run: pip install Pillow --break-system-packages")
    raise

import os

SIZES  = [16, 32, 48, 128]
OUTPUT = os.path.join(os.path.dirname(__file__), 'icons')
os.makedirs(OUTPUT, exist_ok=True)

def make_icon(size):
    img  = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    # Rounded rect background — gradient approximated as purple→pink
    r    = max(2, size // 8)
    draw.rounded_rectangle([0, 0, size-1, size-1], radius=r, fill=(124, 58, 237, 255))

    # Sparkle / lightning letter ✨ approximated with a simple star
    cx, cy = size // 2, size // 2
    s      = size * 0.35

    # Draw a simple 4-point star
    pts = []
    import math
    for i in range(8):
        angle  = math.radians(i * 45 - 90)
        radius = s if i % 2 == 0 else s * 0.45
        pts.append((cx + radius * math.cos(angle), cy + radius * math.sin(angle)))
    draw.polygon(pts, fill=(255, 255, 255, 230))

    path = os.path.join(OUTPUT, f'icon-{size}.png')
    img.save(path, 'PNG')
    print(f'  ✓ icons/icon-{size}.png')

print('Generating extension icons…')
for s in SIZES:
    make_icon(s)
print('Done.')
