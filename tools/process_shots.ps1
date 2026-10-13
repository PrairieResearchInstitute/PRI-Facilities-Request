# Turns raw full-page captures into slide-ready images.
#
# The lesson from three rounds of this: a whole page shrunk onto a slide
# is unreadable, every time. So a page is no longer shrunk - it is cut
# into bands about 620px tall at quiet rows, and each band becomes its
# own slide at full width. A screen becomes a sequence: top of the form,
# middle, actions - each big enough to read from the back.
#
# Outputs base_1.png .. base_N.png per screen (max 4 bands), and writes
# manifest.txt ("name count") so the deck generator knows how many
# slides each screen became. diagram* images are trimmed only - they are
# drawn at slide scale already.
#
#   powershell -File tools\process_shots.ps1

$raw = Join-Path $PSScriptRoot "..\storage\shots\raw"
$out = Join-Path $PSScriptRoot "..\storage\shots"

Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @"
using System;
using System.Collections.Generic;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

public static class ShotFix
{
    static byte[] Grab(Bitmap b, out int stride)
    {
        var d = b.LockBits(new Rectangle(0, 0, b.Width, b.Height),
                           ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
        stride = d.Stride;
        var bytes = new byte[d.Stride * b.Height];
        Marshal.Copy(d.Scan0, bytes, 0, bytes.Length);
        b.UnlockBits(d);
        return bytes;
    }

    static bool Near(byte[] p, int stride, int x, int y, int r, int g, int b, int tol)
    {
        int i = y * stride + x * 4;
        return Math.Abs(p[i + 2] - r) <= tol && Math.Abs(p[i + 1] - g) <= tol
            && Math.Abs(p[i] - b) <= tol;
    }

    static bool RowIsBg(byte[] p, int stride, int w, int y, int r, int g, int b, int tol)
    {
        for (int x = 0; x < w; x += 2)
            if (!Near(p, stride, x, y, r, g, b, tol)) return false;
        return true;
    }

    // Returns how many band files were written for base name dst
    // (dst_1.png, dst_2.png, ...). Diagrams pass split=false and get one.
    public static int Fix(string src, string dst, int pad, bool cropHeader, bool split)
    {
        using (var img = new Bitmap(src))
        {
            int stride; var p = Grab(img, out stride);
            int w = img.Width, h = img.Height;

            int i0 = (h - 3) * stride + (w - 3) * 4;
            int bb = p[i0], bg = p[i0 + 1], br = p[i0 + 2];

            int top = 0;
            if (cropHeader)
            {
                int y = 0;
                while (y < h / 3)
                {
                    int i = y * stride + 8;
                    int lum = (p[i] + p[i + 1] + p[i + 2]) / 3;
                    bool navy = lum < 110;
                    bool orange = p[i + 2] > 150 && p[i + 1] < 120 && p[i] < 90;
                    if (!navy && !orange) break;
                    y++;
                }
                top = y > 20 ? y : 0;
            }

            int t = top, btm = h - 1, l = 0, rgt = w - 1;
            while (t < btm && RowIsBg(p, stride, w, t, br, bg, bb, 12)) t++;
            while (btm > t && RowIsBg(p, stride, w, btm, br, bg, bb, 12)) btm--;
            bool colBg;
            for (; l < rgt; l++)
            {
                colBg = true;
                for (int y = t; y <= btm; y += 2)
                    if (!Near(p, stride, l, y, br, bg, bb, 12)) { colBg = false; break; }
                if (!colBg) break;
            }
            for (; rgt > l; rgt--)
            {
                colBg = true;
                for (int y = t; y <= btm; y += 2)
                    if (!Near(p, stride, rgt, y, br, bg, bb, 12)) { colBg = false; break; }
                if (!colBg) break;
            }
            t = Math.Max(top, t - pad); btm = Math.Min(h - 1, btm + pad);
            l = Math.Max(0, l - pad); rgt = Math.Min(w - 1, rgt + pad);
            int cw = rgt - l + 1, ch = btm - t + 1;

            if (!split)
            {
                using (var one = img.Clone(new Rectangle(l, t, cw, ch), img.PixelFormat))
                    one.Save(dst + ".png", ImageFormat.Png);
                return 1;
            }

            // Cut into bands at quiet rows near multiples of the target
            // height, until the content runs out. No cap: a page takes
            // as many slides as it takes.
            const int target = 620;
            const int maxBands = 40;
            var cuts = new List<int> { t };
            int pos = t;
            while (btm - pos > target + 240 && cuts.Count < maxBands)
            {
                int want = pos + target, cut = want, span = 220;
                for (int d = 0; d < span; d++)
                {
                    if (want + d < btm && RowIsBg(p, stride, w, want + d, br, bg, bb, 12))
                        { cut = want + d; break; }
                    if (want - d > pos + 300 && RowIsBg(p, stride, w, want - d, br, bg, bb, 12))
                        { cut = want - d; break; }
                }
                cuts.Add(cut);
                pos = cut;
            }
            cuts.Add(btm);

            for (int i = 0; i < cuts.Count - 1; i++)
            {
                int y0 = Math.Max(t, cuts[i] - (i > 0 ? 28 : 0));   // a little overlap
                int hh = Math.Min(btm, cuts[i + 1]) - y0 + 1;
                using (var band = img.Clone(new Rectangle(l, y0, cw, hh), img.PixelFormat))
                    band.Save(dst + "_" + (i + 1) + ".png", ImageFormat.Png);
            }
            return cuts.Count - 1;
        }
    }
}
"@

Remove-Item "$out\*.png" -Force -ErrorAction SilentlyContinue
$manifest = @()
Get-ChildItem "$raw\*.png" | ForEach-Object {
    $base = $_.BaseName
    # Drawings and the job-aid form captures are trimmed only: they are
    # single images meant to be shown whole, not banded like a page.
    $isDiagram = ($base -like "diagram*") -or ($base -like "wex_*")
    try {
        if ($isDiagram) {
            [ShotFix]::Fix($_.FullName, (Join-Path $out $base), 10, $false, $false) | Out-Null
            "{0,-24} 1 band (diagram)" -f $base
        } else {
            $n = [ShotFix]::Fix($_.FullName, (Join-Path $out $base), 10, $true, $true)
            $manifest += "$base $n"
            "{0,-24} $n band(s)" -f $base
        }
    } catch {
        "FAILED $($_.Exception.Message) on $($_.Name)"
    }
}
$manifest | Set-Content (Join-Path $out "manifest.txt") -Encoding utf8
"manifest: $($manifest.Count) screens"
