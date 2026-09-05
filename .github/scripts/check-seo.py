"""Validate canonical pages and metadata before deployment; no dependencies."""
from pathlib import Path
from html.parser import HTMLParser
from urllib.parse import urlsplit
import json,re,xml.etree.ElementTree as ET
root=Path(__file__).resolve().parents[2]
class Head(HTMLParser):
 def __init__(self):super().__init__();self.meta={};self.canon=[];self.h1=0
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='meta':self.meta.setdefault(a.get('name',a.get('property')),[]).append(a.get('content',''))
  if tag=='link' and a.get('rel')=='canonical':self.canon.append(a['href'])
  if tag=='h1':self.h1+=1
ns={'s':'http://www.sitemaps.org/schemas/sitemap/0.9'}
urls=[n.text for n in ET.parse(root/'sitemap.xml').findall('s:url/s:loc',ns)]
assert len(urls)==len(set(urls)), 'Duplicate sitemap URL'
titles=set();descriptions=set();count=0
for url in urls:
 u=urlsplit(url);assert u.scheme=='https' and u.netloc=='jpwebcreation.nl',url
 p=root/u.path.lstrip('/');p=p/'index.html' if u.path.endswith('/') else p
 s=p.read_text();h=Head();h.feed(s)
 assert h.canon==[url],f'{p}: canonical'
 assert h.h1==1,f'{p}: H1'
 assert not any('noindex' in v for v in h.meta.get('robots',[])),f'{p}: noindex'
 title=re.findall(r'<title>(.*?)</title>',s,re.S);assert len(title)==1 and title[0] not in titles,f'{p}: title';titles.add(title[0])
 for key in ['description','og:title','og:description','og:url','og:image','twitter:card']:
  assert len(h.meta.get(key,[]))==1 and h.meta[key][0],f'{p}: {key}'
 assert h.meta['description'][0] not in descriptions,f'{p}: duplicate description';descriptions.add(h.meta['description'][0])
 assert h.meta['og:url']==[url],f'{p}: og:url'
 for block in re.findall(r'<script type="application/ld\+json">(.*?)</script>',s,re.S):json.loads(block)
 count+=1
assert 'noindex' in (root/'404.html').read_text()
print(f'SEO metadata and sitemap checked: {count} canonical pages.')
