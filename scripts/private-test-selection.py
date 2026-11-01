#!/usr/bin/env python3
import pathlib,re,sys,xml.etree.ElementTree as ET
mode,base=sys.argv[1],pathlib.Path(sys.argv[2])
if mode=='enumerate':
 for cohort in ['rollback','committed']:
  root=ET.parse(base/('selection-'+cohort+'.xml')).getroot()
  ids=[e.attrib['id'] for e in root.iter() if e.tag.rsplit('}',1)[-1]=='testMethod']
  assert len(ids)==len(set(ids)), 'Duplicate native selected IDs'
  assert all('\n' not in x and '\r' not in x for x in ids)
  (base/('selection-'+cohort+'.ids')).write_text(''.join(x+'\n' for x in ids))
  filters=[]
  for selected in ids:
   method,separator,dataset=selected.partition('#')
   if separator:
    # PHPUnit's XML ID uses method#name; its runtime filter uses
    # `method with data set "name"` (or #index for numeric providers).
    suffix=re.escape(' with data set "'+dataset+'"')
    if dataset.isdecimal(): suffix='(?:'+suffix+'|'+re.escape(' with data set #'+dataset)+')'
    expression=re.escape(method)+suffix
   else: expression=re.escape(selected)
   filters.append('/^'+expression.replace('/','\\/')+'$/\n')
  (base/('selection-'+cohort+'.filters')).write_text(''.join(filters))
elif mode=='aggregate':
 expected=int(sys.argv[3]); root=ET.Element('testsuites'); count=0
 for index in range(1,expected+1):
  p=base/f'cohort-committed-{index:04d}.xml'; r=ET.parse(p).getroot()
  cases=r.findall('.//testcase'); assert len(cases)==1, 'Singleton lifecycle executed unexpected case count'
  count+=len(cases)
  for suite in list(r): root.append(suite)
 assert count==expected
 ET.ElementTree(root).write(base/'cohort-committed.xml',encoding='utf-8',xml_declaration=True)
 print('COMMITTED_LIFECYCLES='+str(count)+' each_fresh_sql=true')
else: raise RuntimeError('Unknown selection operation')
