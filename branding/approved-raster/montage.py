# Документированные параметры утверждённого монтажа (Pillow, LANCZOS).
# Компоненты: hen-crop.png 649x698; wordmark-selected-crop.png 434x62.
# Горизонтальный: холст 1656x378, белый; курочка 231x248 в (65,26),
# надпись 1240x177 в (351,100). Ноги выровнены с baseline.
# Компактный: холст 1330x1377, белый; курочка 870x936 в (230,90),
# надпись 1130x161 в (100,1116).
# Эти размеры исторически увеличены при монтаже; новых деталей не создают.
from pathlib import Path
from PIL import Image
root = Path(__file__).parent
out = root / 'reconstructed'
out.mkdir(exist_ok=True)
for name,size,hw,hh,hx,hy,tw,th,tx,ty in [
 ('horizontal',(1656,378),231,248,65,26,1240,177,351,100),
 ('stacked',(1330,1377),870,936,230,90,1130,161,100,1116)]:
    im=Image.new('RGB',size,'white')
    h=Image.open(root/'components/hen-crop.png').resize((hw,hh),Image.Resampling.LANCZOS)
    t=Image.open(root/'components/wordmark-selected-crop.png').resize((tw,th),Image.Resampling.LANCZOS)
    im.paste(h,(hx,hy)); im.paste(t,(tx,ty))
    im.save(out/f'motorchk-cms-{name}.png')
