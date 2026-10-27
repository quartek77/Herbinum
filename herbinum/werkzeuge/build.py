# Turn raw.pkl into region.json + one JSON per Ort, including generated Bereiche with scores.
import json, math, sys, pickle, random, os
import numpy as np
import shapely
from shapely.geometry import Point, Polygon
from shapely.ops import unary_union
S, OUT = sys.argv[1], sys.argv[2]
R = pickle.load(open(f"{S}/osm/raw.pkl", "rb"))
LAT0, LON0 = 51.74048, 7.66147
KX = math.cos(math.radians(LAT0)) * 111320
KY = 110574
places = json.load(open(f"{S}/osm/places.json", encoding="utf-8"))
for p in places:
    p['x'], p['y'] = (p['lon'] - LON0) * KX, (LAT0 - p['lat']) * KY
os.makedirs(f"{OUT}/ort", exist_ok=True)


def rings(g, tol, q=1, ox=0, oy=0, minarea=0):
    """Polygon(s) -> list of polygons, each a list of flat int rings (exterior + holes)."""
    out = []
    if g.is_empty:
        return out
    g = g.simplify(tol, preserve_topology=True)
    polys = g.geoms if hasattr(g, 'geoms') else [g]
    for p in polys:
        if p.geom_type != 'Polygon' or p.area < minarea:
            continue
        rr = []
        for r in [p.exterior, *p.interiors]:
            c = list(r.coords)[:-1]
            if len(c) < 3:
                continue
            rr.append([v for x, y in c for v in (round((x - ox) / q), round((y - oy) / q))])
        if rr:
            out.append(rr)
    return out


def lineflat(g, tol, q=1, ox=0, oy=0):
    out = []
    if g.is_empty:
        return out
    g = g.simplify(tol)
    ls = g.geoms if hasattr(g, 'geoms') else [g]
    for l in ls:
        if l.geom_type != 'LineString':
            continue
        c = [v for x, y in l.coords for v in (round((x - ox) / q), round((y - oy) / q))]
        if len(c) >= 4:
            out.append(c)
    return out


# ---------- region ----------
Q = 10  # region coords in decameters
areas, lines, pois = R['areas'], R['lines'], R['pois']
settle_u = unary_union([a['g'].buffer(0) for a in areas if a['k'] in
                        ('landuse=residential', 'landuse=commercial', 'landuse=industrial', 'landuse=retail')]).buffer(25).buffer(-25)
forest_u = unary_union([a['g'].buffer(0) for a in areas if a['k'] in ('landuse=forest', 'natural=wood')])
water_u = unary_union([a['g'].buffer(0) for a in areas if a['k'] == 'natural=water'])
region = dict(q=Q, orte=[], settle=rings(settle_u, 15, Q, minarea=8000), forest=rings(forest_u, 25, Q, minarea=30000),
              water=rings(water_u, 10, Q, minarea=8000), roads=[], rail=[], rivers=[], pois=[], places=[])
for l in lines:
    if l['k'] == 'road' and l['c'] <= 4:
        for c in lineflat(l['g'], 12, Q):
            region['roads'].append([l['c'], l['ref'] or '', c])
    elif l['k'] == 'rail':
        for c in lineflat(l['g'], 12, Q):
            region['rail'].append(c)
    elif l['k'] == 'water' and (l['c'] == 1 or l['n']):
        for c in lineflat(l['g'], 12, Q):
            region['rivers'].append([l['c'], l['n'] or '', c])
for k, n, x, y, i in pois:
    if k in ('castle', 'station') and n:
        region['pois'].append([k, n, round(x / Q), round(y / Q)])


# ---------- per Ort ----------
def kmeans(pts, k, seed=1):
    rng = np.random.default_rng(seed)
    c = pts[rng.choice(len(pts), k, replace=False)]
    for _ in range(40):
        lab = ((pts[:, None, :] - c[None, :, :]) ** 2).sum(2).argmin(1)
        c = np.array([pts[lab == j].mean(0) if (lab == j).any() else c[j] for j in range(k)])
    return c


DIRS = ['Ost', 'Südost', 'Süd', 'Südwest', 'West', 'Nordwest', 'Nord', 'Nordost']


def dirname(dx, dy):
    a = math.degrees(math.atan2(dy, dx)) % 360  # y points south, so 90° = Süd
    return DIRS[int(((a + 22.5) % 360) // 45)]


def clamp(v, a=0, b=100):
    return max(a, min(b, v))


for (oid, name, lat, lon, rad), (_, ox, oy, _) in zip(R['orte'], R['oc']):
    circ = Point(ox, oy).buffer(rad, 64)
    view = Point(ox, oy).buffer(rad + 180, 64)
    A = [a for a in areas if a['g'].intersects(view)]
    blds = [a for a in A if a['k'] == 'building']
    L = [l for l in lines if l['g'].intersects(view)]
    P = [p for p in pois if view.contains(Point(p[2], p[3]))]

    # Bereiche: k-means over building centroids (inner quarters) + a ring of outer seeds (Bauerschaften)
    bc = np.array([[b['g'].centroid.x, b['g'].centroid.y] for b in blds if circ.contains(b['g'].centroid)])
    kin = int(clamp(round(len(bc) / 260), 4, 9))
    seeds = list(kmeans(bc, kin, seed=len(name))) if len(bc) > kin * 5 else []
    nout = 4 if rad < 1000 else 5
    for i in range(nout):
        ang = 2 * math.pi * i / nout + 0.4
        seeds.append(np.array([ox + math.cos(ang) * rad * 0.86, oy + math.sin(ang) * rad * 0.86]))
    pts = []
    for s in seeds:
        if all(np.hypot(*(s - t)) > rad * 0.33 for t in pts):
            pts.append(s)
    vor = shapely.voronoi_polygons(shapely.MultiPoint([tuple(p) for p in pts]), extend_to=view.buffer(2000))
    cells = []
    for p in pts:
        for g in vor.geoms:
            if g.contains(Point(*p)):
                cells.append(g.intersection(circ))
                break

    def U(*ks):
        gs = [a['g'].buffer(0) for a in A if a['k'] in ks]
        return unary_union(gs) if gs else Polygon()

    lay = dict(built=U('landuse=residential'),
               gewerbe=U('landuse=commercial', 'landuse=industrial', 'landuse=retail'),
               wald=U('landuse=forest', 'natural=wood'),
               wasser=U('natural=water', 'natural=wetland'),
               gruen=U('leisure=park', 'landuse=meadow', 'landuse=grass', 'landuse=village_green', 'landuse=recreation_ground',
                       'landuse=allotments', 'landuse=orchard', 'leisure=golf_course'),
               sport=U('leisure=pitch', 'leisure=sports_centre', 'leisure=stadium'),
               hof=U('landuse=farmyard'))
    bunion = unary_union([b['g'].buffer(0) for b in blds]) if blds else Polygon()
    worship = [p for p in P if p[0] == 'worship']
    core_pt = min(worship, key=lambda p: (p[2] - ox) ** 2 + (p[3] - oy) ** 2) if worship else None
    used = set()
    ber = []
    rng = random.Random(oid)
    for cell in cells:
        if cell.is_empty:
            continue
        ar = cell.area
        ha = ar / 1e4
        sh = {k: (v.intersection(cell).area / ar if not v.is_empty else 0) for k, v in lay.items()}
        bshare = bunion.intersection(cell).area / ar if not bunion.is_empty else 0
        rl = {c: sum(l['g'].intersection(cell).length for l in L if l['k'] == 'road' and l['c'] == c) for c in (1, 2, 3, 4, 5)}
        kinds = [p[0] for p in P if cell.contains(Point(p[2], p[3]))]
        water_len = sum(l['g'].intersection(cell).length for l in L if l['k'] == 'water')
        cx, cy = cell.centroid.x, cell.centroid.y
        dist = math.hypot(cx - ox, cy - oy) / rad
        acker = max(0, 1 - sh['built'] - sh['gewerbe'] - sh['wald'] - sh['wasser'] - sh['gruen'] - sh['sport'] - sh['hof'])

        # name: Kern / Bauerschaft from OSM hamlets / longest street / compass fallback
        nm, typ = None, 'Siedlung'
        if core_pt and cell.contains(Point(core_pt[2], core_pt[3])):
            nm = 'Altstadt' if oid in ('werne', 'luedinghausen', 'drensteinfurt') else 'Ortsmitte' if rad >= 1250 else 'Dorfkern'
            typ = 'Kern'
        if not nm and dist > 0.55:
            hp = [p for p in places if p['place'] in ('hamlet', 'locality', 'neighbourhood', 'quarter')
                  and cell.contains(Point(p['x'], p['y'])) and p['name'] not in used]
            if hp:
                nm = min(hp, key=lambda p: (p['x'] - cx) ** 2 + (p['y'] - cy) ** 2)['name']
        if not nm:
            sl = {}
            for l in L:
                if l['k'] == 'road' and l['n'] and l['c'] >= 3:
                    ln = l['g'].intersection(cell).length
                    if ln > 0:
                        sl[l['n']] = sl.get(l['n'], 0) + ln
            for s, _ in sorted(sl.items(), key=lambda x: -x[1]):
                if s not in used:
                    nm = s
                    break
        if not nm:
            nm = f"Feldmark {dirname(cx - ox, cy - oy)}"
            if nm in used:
                nm += " II"
        if typ != 'Kern':
            if sh['gewerbe'] > 0.18:
                typ = 'Gewerbe'
            elif acker > 0.5:
                typ = 'Bauerschaft'
        used.add(nm)

        # scores (0-100) derived from the real land use inside the cell
        felder = int(clamp(round(14 + ha * 0.09 + acker * 10 - (8 if typ == 'Kern' else 0)), 12, 40))
        lebq = clamp(45 + sh['gruen'] * 120 + sh['wald'] * 50 + min(water_len, 800) / 20 + kinds.count('school') * 6
                     + kinds.count('pub') * 4 + kinds.count('worship') * 5 - sh['gewerbe'] * 90 - rl[1] / 40 - rl[2] / 120
                     + (5 if typ == 'Bauerschaft' else 0))
        wehr = clamp(30 + kinds.count('castle') * 30 + sh['wald'] * 60 + sh['wasser'] * 150 + min(water_len, 1200) / 40
                     + (15 if typ == 'Kern' else 0) + bshare * 60 - (rl[1] + rl[2]) / 80 + rng.uniform(-6, 6))
        eink = clamp(20 + sh['gewerbe'] * 140 + (rl[2] + rl[3]) / 45 + rl[4] / 90 + kinds.count('station') * 20
                     + kinds.count('pub') * 5 + (18 if typ == 'Kern' else 0) + bshare * 80)
        mod = dict(holz=round(0.8 + sh['wald'] * 1.6 + rng.uniform(0, .15), 2),
                   ziegel=round(0.85 + bshare * 2.2 + sh['gewerbe'] * 0.8 + rng.uniform(0, .25), 2),
                   korn=round(0.7 + acker * 0.9 + sh['hof'] * 2 + rng.uniform(0, .1), 2),
                   strom=round(0.8 + acker * 0.5 + (0.2 if typ == 'Gewerbe' else 0) + rng.uniform(0, .15), 2))
        rp = cell.representative_point()
        ber.append(dict(name=nm, typ=typ, ha=round(ha, 1), felder=felder,
                        score=dict(lebensqualitaet=round(lebq), wehr=round(wehr), einkommen=round(eink)), mod=mod,
                        mix=dict(wald=round(sh['wald'], 2), acker=round(acker, 2), wasser=round(sh['wasser'], 2), bebaut=round(bshare, 2)),
                        lx=round(rp.x - ox), ly=round(rp.y - oy), poly=rings(cell, 2, 1, ox, oy)))
    ber.sort(key=lambda b: (b['typ'] != 'Kern', math.hypot(b['lx'], b['ly'])))
    for i, b in enumerate(ber):
        b['id'], b['nr'] = f"{oid}-{i + 1}", i + 1
    d = dict(id=oid, name=name, r=rad,
             buildings=[r for b in blds for r in rings(b['g'].intersection(view), 0.8, 1, ox, oy)],
             areas=[[a['k'].split('=')[1], rr] for a in A if a['k'] != 'building'
                    for rr in rings(a['g'].buffer(0).intersection(view), 2, 1, ox, oy, minarea=60)],
             roads=[[l['c'], l['n'] or '', c] for l in L if l['k'] == 'road' for c in lineflat(l['g'].intersection(view), 1.5, 1, ox, oy)],
             rail=[c for l in L if l['k'] == 'rail' for c in lineflat(l['g'].intersection(view), 2, 1, ox, oy)],
             water=[[l['c'], l['n'] or '', c] for l in L if l['k'] == 'water' for c in lineflat(l['g'].intersection(view), 2, 1, ox, oy)],
             pois=[[k, n or '', round(x - ox), round(y - oy)] for k, n, x, y, i in P],
             bereiche=ber)
    json.dump(d, open(f"{OUT}/ort/{oid}.json", "w", encoding="utf-8"), ensure_ascii=False, separators=(',', ':'))
    region['orte'].append(dict(id=oid, name=name, x=round(ox / Q), y=round(oy / Q), r=rad, n=len(ber),
                               typ='Stadt' if rad >= 1250 else 'Dorf'))
    print(f"{name:15} bld={len(blds):5} bereiche={len(ber):2} size={os.path.getsize(f'{OUT}/ort/{oid}.json') // 1024}KB  "
          + ", ".join(b['name'] + '/' + b['typ'][0] for b in ber), flush=True)
json.dump(region, open(f"{OUT}/region.json", "w", encoding="utf-8"), ensure_ascii=False, separators=(',', ':'))
print("region", os.path.getsize(f"{OUT}/region.json") // 1024, "KB")
