import numpy as np, sys
from PIL import Image
from scipy import ndimage as nd
S='/home/box/agent-data/motorchk-branding-approved-raster/delivery/'
T=int(sys.argv[1]) if len(sys.argv)>1 else 60     # "светлый" пиксель
MAXD=float(sys.argv[2]) if len(sys.argv)>2 else 4  # дальше от заливки - чистый фон
K8=np.ones((3,3))

def zones(a):
    dw=(255-a).max(2)
    light=dw<T
    lab,n=nd.label(light,structure=K8)
    border=set(np.unique(np.concatenate([lab[0],lab[-1],lab[:,0],lab[:,-1]])))-{0}
    Z=np.isin(lab,list(border))
    holes=[]
    for i,sl in enumerate(nd.find_objects(lab)):
        k=i+1
        if k in border or sl is None: continue
        comp=lab[sl]==k
        if comp.sum()<150: continue
        core=comp&(dw[sl]<=8)
        if core.sum()<100: continue
        cc=a[sl][comp].astype(int)
        if ((cc.min(1)>150)&((cc[:,0]-cc[:,2])>12)).mean()>0.3: continue   # кремовое тело курицы - не дырка        # дырка должна иметь чисто белую середину
        pad=8
        y0,y1=max(sl[0].start-pad,0),sl[0].stop+pad; x0,x1=max(sl[1].start-pad,0),sl[1].stop+pad
        m=np.zeros(lab.shape,bool); m[sl]=comp
        mm=m[y0:y1,x0:x1]
        ring=nd.binary_dilation(mm,iterations=6)&~mm
        rc=a[y0:y1,x0:x1][ring].astype(int)
        cream=(rc.min(1)>150)&((rc[:,0]-rc[:,2])>12)
        if cream.mean()<0.15:
            Z|=m; holes.append((k,int(comp.sum())))
    return Z,holes,dw

def extract(a):
    Z,holes,dw=zones(a)
    a=a.astype(float); W=np.array([255.,255,255])
    R=float(sys.argv[3]) if len(sys.argv)>3 else 2
    dZ=nd.distance_transform_edt(~Z)          # расстояние до светлой зоны
    U=Z|(dZ<=R)                                 # всё, что может быть смесью с фоном
    CORE=~U                                     # чистые заливки
    dist,idx=nd.distance_transform_edt(U,return_indices=True)
    F=a[idx[0],idx[1]]                          # цвет ближайшей чистой заливки
    d=F-W; P=a-W
    den=(d*d).sum(2); den[den<1]=1
    al=np.clip((P*d).sum(2)/den,0,1)
    al=np.where(Z,np.clip((al-0.04)/0.96,0,1),al)
    kill=Z&((dw<=8)|(dist>MAXD+R))
    al[kill]=0
    al[CORE]=1
    # неизвестная полоса: цвет = заливка; для почти непрозрачных - честный unpremultiply
    with np.errstate(divide='ignore',invalid='ignore'):
        un=np.clip(W+P/np.maximum(al,1e-3)[...,None],0,255)
    t=np.clip((al-0.7)/0.3,0,1)[...,None]
    C=np.where(U[...,None],F*(1-t)+un*t,a)
    out=np.dstack([C,al*255]).round().clip(0,255).astype(np.uint8)
    return out,holes,Z

if __name__=='__main__':
    for f in ['motorchk-cms-horizontal.png','motorchk-cms-stacked.png']:
        a=np.asarray(Image.open(S+f).convert('RGB'))
        out,holes,Z=extract(a)
        Image.fromarray(out,'RGBA').save('work-'+f,optimize=True)
        o=out.astype(float); al=o[...,3:]/255
        err=np.abs(o[...,:3]*al+255*(1-al)-a)
        cream=((a.min(2)>150)&((a[...,0].astype(int)-a[...,2])>12))
        print(f,'holes',holes,'cream in Z',int((Z&cream).sum()),'/',int(cream.sum()),'err max',err.max().round(1),'p99.9',np.percentile(err,99.9).round(1))
