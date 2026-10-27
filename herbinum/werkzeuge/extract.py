# Extract Herbinum map features from OSM PBF extracts into a local metric frame (Herbern = 0,0; y south).
import osmium, json, math, sys, pickle
from shapely import wkb
S=sys.argv[1]
LAT0,LON0=51.74048,7.66147
KX=math.cos(math.radians(LAT0))*111320; KY=110574
BB=(51.62,7.39,51.92,7.89)
ORTE=[("herbern","Herbern",51.74048,7.66147,1150),("ascheberg","Ascheberg",51.78867,7.61968,1250),
("davensberg","Davensberg",51.81985,7.59186,800),("capelle","Capelle",51.73166,7.59229,800),
("suedkirchen","Südkirchen",51.71013,7.54707,850),("nordkirchen","Nordkirchen",51.73802,7.52518,1100),
("werne","Werne",51.66268,7.63551,1700),("stockum","Stockum",51.6726,7.69199,900),
("drensteinfurt","Drensteinfurt",51.79727,7.74181,1300),("walstedde","Walstedde",51.76162,7.79498,800),
("rinkerode","Rinkerode",51.84574,7.68475,850),("ottmarsbocholt","Ottmarsbocholt",51.82298,7.54202,800),
("amelsbueren","Amelsbüren",51.88339,7.60598,1000),("luedinghausen","Lüdinghausen",51.77178,7.44464,1600),
("selm","Selm",51.70055,7.46861,1500),("bork","Bork",51.66514,7.46723,1100),
("cappenberg","Cappenberg",51.65698,7.52978,800),("bockum-hoevel","Bockum-Hövel",51.6987,7.7467,1500),
("heessen","Heessen",51.70584,7.83412,1300),("mersch","Mersch",51.76399,7.73535,700),
("albersloh","Albersloh",51.86794,7.73324,900)]
def P(lat,lon): return ((lon-LON0)*KX,(LAT0-lat)*KY)
OC=[(o[0],*P(o[2],o[3]),o[4]) for o in ORTE]
def orte_near(x,y,pad=250):
    return [oid for oid,ox,oy,r in OC if (x-ox)**2+(y-oy)**2<(r+pad)**2]
RC={'motorway':1,'motorway_link':1,'trunk':2,'trunk_link':2,'primary':2,'primary_link':2,'secondary':3,'secondary_link':3,
    'tertiary':4,'tertiary_link':4,'unclassified':5,'residential':5,'living_street':5,'pedestrian':5}
AREA={'landuse':{'residential','commercial','industrial','retail','farmyard','cemetery','forest','meadow','grass','allotments','recreation_ground','village_green','orchard','religious'},
      'natural':{'wood','water','wetland','scrub'},'leisure':{'park','pitch','sports_centre','stadium','golf_course','swimming_pool'},'amenity':{'school','place_of_worship','grave_yard'},'historic':{'castle'}}
lines=[]; areas=[]; pois=[]; seen=set()
wf=osmium.geom.WKBFactory()
def proj(geom):
    from shapely.ops import transform
    return transform(lambda lo,la,z=None: ((lo-LON0)*KX,(LAT0-la)*KY), geom)
def inbb(lat,lon): return BB[0]<lat<BB[2] and BB[1]<lon<BB[3]
POI_KEYS=lambda t: ('worship' if t.get('amenity')=='place_of_worship' else 'castle' if (t.get('historic') in ('castle','manor') or t.get('building')=='castle') else
    'station' if t.get('railway') in ('station','halt') else 'school' if t.get('amenity')=='school' else 'mill' if t.get('man_made') in ('windmill','watermill') else
    'townhall' if t.get('amenity')=='townhall' else 'pub' if t.get('amenity') in ('pub','restaurant','biergarten') and t.get('name') else 'sport' if t.get('leisure') in ('stadium','sports_centre') else None)
class H(osmium.SimpleHandler):
    def node(self,n):
        if not n.tags: return
        k=POI_KEYS(n.tags)
        if k and n.location.valid() and inbb(n.location.lat,n.location.lon):
            pois.append((k,n.tags.get('name'),*P(n.location.lat,n.location.lon),'n%d'%n.id))
    def way(self,w):
        t=w.tags
        hw=t.get('highway'); rw=t.get('railway'); ww=t.get('waterway')
        cls=None
        if hw in RC: cls=('road',RC[hw])
        elif rw=='rail' and not t.get('service'): cls=('rail',0)
        elif ww in ('river','canal','stream'): cls=('water',{'river':1,'canal':1,'stream':2}[ww])
        if not cls: return
        if ('w',w.id) in seen: return
        try:
            n0=w.nodes[0].location; n1=w.nodes[len(w.nodes)-1].location
        except Exception: return
        if not (inbb(n0.lat,n0.lon) or inbb(n1.lat,n1.lon)): return
        try: g=wkb.loads(wf.create_linestring(w),hex=True)
        except Exception: return
        seen.add(('w',w.id))
        lines.append(dict(k=cls[0],c=cls[1],n=t.get('name'),ref=t.get('ref'),g=proj(g)))
    def area(self,a):
        t=a.tags
        kind=None
        if 'building' in t: kind='building'
        for key,vals in AREA.items():
            v=t.get(key)
            if v in vals: kind=f"{key}={v}"; break
        if t.get('water') and not kind: kind='natural=water'
        if not kind: return
        oid=('a',a.orig_id(),a.from_way())
        if oid in seen: return
        try: g=proj(wkb.loads(wf.create_multipolygon(a),hex=True))
        except Exception: return
        c=g.centroid
        if not (-22000<c.x<17000 and -19500<c.y<20000): return
        seen.add(oid)
        if kind=='building':
            if not orte_near(c.x,c.y,150): return
        k=POI_KEYS(t)
        if k: pois.append((k,t.get('name'),c.x,c.y,'a%d'%a.orig_id()))
        areas.append(dict(k=kind,n=t.get('name'),g=g))
for f in ('muenster','arnsberg'):
    H().apply_file(f"{S}/pbf/{f}.osm.pbf",locations=True,idx='flex_mem')
    print(f,len(lines),len(areas),len(pois),flush=True)
pickle.dump(dict(lines=lines,areas=areas,pois=pois,orte=ORTE,oc=OC),open(f"{S}/osm/raw.pkl","wb"))
print("done")
