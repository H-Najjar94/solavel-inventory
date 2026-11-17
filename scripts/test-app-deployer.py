#!/usr/bin/env python3
"""Unprivileged filesystem/identity/rollback fixtures; no production services or databases."""
from pathlib import Path
import subprocess,tempfile,os,json
assert os.geteuid()!=0,'Fixtures must run unprivileged'
script=Path(__file__).with_name('deploy-app-release.sh');base=Path(tempfile.mkdtemp(prefix='solastock-app-deploy-test-'));results=[]
def cmd(args,cwd=None,check=True,env=None):return subprocess.run(args,cwd=cwd,check=check,capture_output=True,text=True,env=env)
def fixture(name):
 p=base/name;p.mkdir();repo=p/'repo';repo.mkdir();remote=p/'remote.git';root=p/'root';(root/'releases').mkdir(parents=True);(root/'shared/storage/app/public').mkdir(parents=True);(root/'shared/.env').write_text('FIXTURE_ONLY=1\n');(root/'shared/storage/app/keep.txt').write_text('do not discard uploads')
 cmd(['git','init','-q',str(repo)]);cmd(['git','config','user.email','fixture@example.invalid'],repo);cmd(['git','config','user.name','Release fixture'],repo)
 for name,text in {'app/Item.php':'<?php class FixtureItem {}\n','bootstrap/app.php':'<?php return true;\n','public/index.php':'<?php echo "fixture";\n','artisan':'<?php\n','composer.json':'{}\n','package.json':'{}\n','storage/.gitignore':'*\n!.gitignore\n'}.items():
  f=repo/name;f.parent.mkdir(parents=True,exist_ok=True);f.write_text(text)
 cmd(['git','add','.'],repo);cmd(['git','commit','-qm','baseline'],repo);oldsha=cmd(['git','rev-parse','HEAD'],repo).stdout.strip();old=root/'releases/20261117T000000Z-oldfixture';old.mkdir();archive=cmd(['git','archive',oldsha],repo).stdout if False else subprocess.check_output(['git','-C',str(repo),'archive',oldsha]);subprocess.run(['tar','-x','-C',str(old)],input=archive,check=True)
 (old/'storage/.gitignore').unlink();(old/'storage').rmdir();(old/'storage').symlink_to(root/'shared/storage');(old/'RELEASE_SHA').write_text(oldsha+'\n');(root/'current').symlink_to(old);(root/'PREVIOUS_RELEASE').write_text('fixture-previous\n')
 (repo/'app/Item.php').write_text('<?php class FixtureItem { public const RELEASE=2; }\n');cmd(['git','add','.'],repo);cmd(['git','commit','-qm','feature'],repo);sha=cmd(['git','rev-parse','HEAD'],repo).stdout.strip();branch=cmd(['git','branch','--show-current'],repo).stdout.strip();cmd(['git','init','--bare','-q',str(remote)]);cmd(['git','remote','add','origin',str(remote)],repo);cmd(['git','push','-q','origin',branch],repo)
 args=['bash',str(script),'--test-mode','--root',str(root),'--repo',str(repo),'--sha',sha,'--branch',branch,'--baseline-sha',oldsha,'--expected-current-release',old.name,'--expected-current-sha',oldsha]
 return repo,root,old,sha,args
for name,failpoint in [('success',None),('build_only',None),('dirty_source','dirty'),('stale_live','stale'),('lost_hotfix','baseline'),('schema_refusal','preflight'),('low_space','space'),('post_failure','post_activate'),('live_drift','live_source_drift'),('symlink_root','symlink_root')]:
 repo,root,old,sha,args=fixture(name);env=os.environ.copy();env['STOCK_DEPLOY_TEST_FREE_BYTES']=str(10*1024**3)
 if name!='build_only':args+=['--activate']
 if failpoint=='dirty':(repo/'untracked-source.php').write_text('<?php')
 if failpoint=='stale':(old/'RELEASE_SHA').write_text('0'*40+'\n')
 if failpoint=='baseline':(old/'app/Item.php').write_text('<?php // live hotfix not yet reconciled\n')
 if failpoint=='symlink_root':
  real=root.with_name('real-root');root.rename(real);root.symlink_to(real);old=(real/'releases'/old.name)
 if failpoint=='space':env['STOCK_DEPLOY_TEST_FREE_BYTES']='1'
 if failpoint in ['preflight','post_activate','live_source_drift']:env['STOCK_DEPLOY_TEST_FAIL']=failpoint
 run=cmd(args,check=False,env=env);(base/f'{name}.log').write_text(run.stdout+run.stderr)
 expect=name in ['success','build_only'];assert (run.returncode==0)==expect,(name,run.stdout,run.stderr)
 if name=='success':
  current=(root/'current').resolve();assert current!=old and (current/'RELEASE_SHA').read_text().strip()==sha;assert (current/'storage').resolve()==root/'shared/storage';assert (current/'.env').resolve()==root/'shared/.env';assert (root/'PREVIOUS_RELEASE').read_text().strip()==str(old)
 else:assert (root/'current').resolve()==old,name
 assert (root/'shared/storage/app/keep.txt').read_text()=='do not discard uploads'
 if name=='post_failure':assert (root/'PREVIOUS_RELEASE').read_text().strip()=='fixture-previous'
 results.append({'case':name,'status':'PASS','exit_code':run.returncode})
print(json.dumps({'status':'PASS','cases':results,'fixtures':str(base),'production_touched':False},indent=2))
Path(__file__).with_name('deployer-fixture-results.json').write_text(json.dumps({'status':'PASS','cases':results,'fixtures':str(base),'production_touched':False},indent=2)+'\n')
