# Turns the supplied black-on-white Pyrops silhouette into the banner
# asset: trimmed to its content, black becomes white, the background
# becomes transparent, and the anti-aliased edge greys become partial
# alpha so the mark stays smooth on the navy.
#
#   powershell -File tools\make_logo_asset.ps1

$src = Join-Path $PSScriptRoot "..\Priops Silloutte.png"
$dst = Join-Path $PSScriptRoot "..\facilities\assets\priops.png"

Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @"
using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.Runtime.InteropServices;

public static class LogoFix
{
    public static string Make(string src, string dst)
    {
        using (var img = new Bitmap(src))
        {
            var d = img.LockBits(new Rectangle(0, 0, img.Width, img.Height),
                                 ImageLockMode.ReadOnly, PixelFormat.Format32bppArgb);
            var p = new byte[d.Stride * img.Height];
            Marshal.Copy(d.Scan0, p, 0, p.Length);
            img.UnlockBits(d);
            int w = img.Width, h = img.Height, stride = d.Stride;

            // Content bounds: anything meaningfully darker than paper.
            int t = h, b = -1, l = w, r = -1;
            for (int y = 0; y < h; y++)
            {
                for (int x = 0; x < w; x++)
                {
                    int i = y * stride + x * 4;
                    int lum = (p[i] + p[i + 1] + p[i + 2]) / 3;
                    if (lum < 200)
                    {
                        if (y < t) t = y;
                        if (y > b) b = y;
                        if (x < l) l = x;
                        if (x > r) r = x;
                    }
                }
            }
            int pad = 12;
            t = Math.Max(0, t - pad); l = Math.Max(0, l - pad);
            b = Math.Min(h - 1, b + pad); r = Math.Min(w - 1, r + pad);
            int cw = r - l + 1, ch = b - t + 1;

            using (var outB = new Bitmap(cw, ch, PixelFormat.Format32bppArgb))
            {
                var od = outB.LockBits(new Rectangle(0, 0, cw, ch),
                                       ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);
                var q = new byte[od.Stride * ch];
                for (int y = 0; y < ch; y++)
                {
                    for (int x = 0; x < cw; x++)
                    {
                        // Mirrored on the vertical axis: the mark
                        // faces the wordmark, as the lion does.
                        int i = (y + t) * stride + (r - x) * 4;
                        int lum = (p[i] + p[i + 1] + p[i + 2]) / 3;
                        int a = 255 - lum;             // dark -> opaque
                        if (a < 10) a = 0;             // paper -> fully clear
                        int j = y * od.Stride + x * 4;
                        q[j] = 255; q[j + 1] = 255; q[j + 2] = 255;   // white
                        q[j + 3] = (byte)a;
                    }
                }
                Marshal.Copy(q, 0, od.Scan0, q.Length);
                outB.UnlockBits(od);
                outB.Save(dst, ImageFormat.Png);
                return cw + "x" + ch;
            }
        }
    }
}
"@

[LogoFix]::Make($src, $dst)
