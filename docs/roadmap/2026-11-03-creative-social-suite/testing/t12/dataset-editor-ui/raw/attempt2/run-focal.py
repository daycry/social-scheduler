from pathlib import Path
import json,subprocess,sys
here=Path('storage/framework/testing/t12-dataset-editor-ui/attempt2')
command=['node','--max-old-space-size=512','--experimental-test-isolation=none','--test','--test-name-pattern=dialog save invalidates a rendered dirty cache','tests/frontend/studio-datasets-dialog.test.mjs']
r=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=60)
(here/(sys.argv[1]+'.log')).write_bytes(r.stdout)
(here/(sys.argv[1]+'.json')).write_text(json.dumps({'command':command,'exit_code':r.returncode},indent=2)+'\n',encoding='utf-8')
print(r.stdout.decode('utf-8'));print('EXIT_CODE='+str(r.returncode))
