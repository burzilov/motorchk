"""Черновики прозрачных PNG логотипа Motorchk CMS из утверждённых растров (без перерисовки)."""
import numpy as np, sys, os
from PIL import Image
from scipy import ndimage as nd
sys.argv=[sys.argv[0],'60','4','4']
import extract2
S=extract2.S
OUT='/workspace/logo-transparent'
PAPER=np.array([0xf8,0xf7,0xf3],float)   # --color-paper  oklch(97.5% 0.005 90)

def save(arr,name):
    Image.fromarray(arr,'RGBA').save(os.path.join(OUT,name),optimize=True)

def lin(c): c=c/255; return np.where(c<=0.04045,c/12.92,((c+0.055)/1.055)**2.4)
def enc(l): l=np.clip(l,0,1); return 255*np.where(l<=0.0031308,12.92*l,1.055*l**(1/2.4)-0.055)

# 1) логин: компактный знак, только снят фон
st=np.asarray(Image.open(S+'motorchk-cms-stacked.png').convert('RGB'))
st_out,_,_=extract2.extract(st); save(st_out,'motorchk-cms-stacked.png')

# 2) горизонтальный: прозрачный, цвета как в оригинале (для светлых фонов)
hz=np.asarray(Image.open(S+'motorchk-cms-horizontal.png').convert('RGB'))
hz_out,_,_=extract2.extract(hz); save(hz_out,'motorchk-cms-horizontal.png')

# 3) горизонтальный под тёмную шапку
d=hz_out.astype(float); rgb=d[...,:3]; al=d[...,3]
H,Wd=al.shape; X=np.arange(Wd)[None,:].repeat(H,0)
ch=rgb.max(2)-rgb.min(2)
word=(X>=330)&(X<1340)&(ch<28)&(al>0)           # надпись Motorchk: нейтрально-серая
g=rgb.mean(2)
# инверсия тона: заливка (~70) -> бумага, тёмная окантовка чуть светлее, светлые блики чуть темнее
k=np.clip((g-70)*0.25,-8,40)[...,None]
rgb=np.where(word[...,None],np.clip(PAPER-k,0,255),rgb)
# CMS: тот же оттенок, светлее в линейном свете x2 (контраст на #0f141e ~5:1 вместо ~3:1)
cms=(X>=1340)&(al>0)&(ch>=28)
rgb=np.where(cms[...,None],enc(lin(rgb)*2.0),rgb)
dark=np.dstack([rgb,al]).round().clip(0,255).astype(np.uint8)
save(dark,'motorchk-cms-horizontal-on-dark.png')

# 3b) вариант с тонкой светлой обводкой силуэта курицы (лапки иначе тонут в чёрном)
hen=(X<330)
a0=(al/255)*hen
r_src=int(os.environ.get("RSRC","10"))   # ширина светлого контура в px исходника (~1 CSS px при 158 px)
dt=nd.distance_transform_edt(a0<0.5)
ring=np.clip(r_src+0.5-dt,0,1)          # сглаженный контур
filled=nd.binary_fill_holes(a0>=0.5)
holes_in=filled&(a0<0.5)                 # ушки ключа: остаются прозрачными
ring=np.where(nd.binary_dilation(holes_in,iterations=2)&(a0<0.5),0,ring)
ring=np.maximum(ring,a0)
ra=ring*hen
# подложка цвета бумаги под курицей, сверху исходная курица
base_a=ra; top_a=a0
out_a=top_a+base_a*(1-top_a)
out_rgb=np.where(out_a[...,None]>0,(rgb*top_a[...,None]+PAPER*base_a[...,None]*(1-top_a[...,None]))/np.maximum(out_a,1e-6)[...,None],rgb)
fa=np.where(hen,out_a*255,al)
frgb=np.where(hen[...,None],out_rgb,rgb)
save(np.dstack([frgb,fa]).round().clip(0,255).astype(np.uint8),'motorchk-cms-horizontal-on-dark-outline.png')
print('ok')
