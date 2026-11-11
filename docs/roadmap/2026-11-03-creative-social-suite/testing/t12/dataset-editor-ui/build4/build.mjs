import {build} from 'vite';
import {fileURLToPath} from 'node:url';
import config from '../../../../vite.config.js';
const envDir=fileURLToPath(new URL('./empty-env/',import.meta.url));
await build({...config,configFile:false,envDir});
for(const [entry,name,fileName] of [
 ['resources/js/Studio/render-entry.js','StudioRender','studio-renderer.js'],
 ['resources/js/Studio/project-render-entry.js','StudioProjectRender','studio-project-renderer.js']
]) await build({configFile:false,envDir,publicDir:false,build:{outDir:'public/build',emptyOutDir:false,lib:{entry,name,formats:['iife'],fileName:()=>fileName}}});
