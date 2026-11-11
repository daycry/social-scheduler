import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import time
import unittest
import uuid


def module():
    spec = importlib.util.spec_from_file_location('studio_av_linux', 'scripts/studio/av-linux.py')
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


@unittest.skipUnless(sys.platform == 'linux' and os.environ.get('STUDIO_AV_CGROUP_TEST') == '1', 'requires explicit STUDIO_AV_CGROUP_TEST=1 in a real delegated bounded Linux cgroup')
class LinuxPhaseTest(unittest.TestCase):
    def test_child_enters_owned_subgroup_before_exec_and_cleanup_removes_only_that_subgroup(self):
        try:
            phase = module().create_phase(str(uuid.uuid4()), seconds=5)
        except RuntimeError as error:
            self.fail(f'phase unavailable: {error}')
        try:
            child = subprocess.run([sys.executable, '-c', "import pathlib,json,os;print(json.dumps({'pid':os.getpid(),'group':pathlib.Path('/proc/self/cgroup').read_text()}))"],
                                   capture_output=True, text=True, timeout=3, preexec_fn=phase.join_child)
            self.assertEqual(child.returncode, 0, child.stderr)
            data = json.loads(child.stdout)
            self.assertIn(phase.directory.name, data['group'])
            self.assertEqual(phase.directory.parent, phase.root)
            self.assertTrue(phase.directory.is_dir())
            self.assertGreater(phase.limit, 0)
            self.assertLessEqual(phase.limit, 3 * 1024**3)
        finally:
            phase.close()
        self.assertFalse(phase.directory.exists())
        self.assertTrue(phase.root.exists())

    def test_guardian_deadline_kills_detached_descendants(self):
        phase = module().create_phase(str(uuid.uuid4()), seconds=0.4)
        runner = "import os,subprocess,sys,time,json;os.setsid();child=subprocess.Popen([sys.executable,'-c','import os,time;os.setsid();time.sleep(30)']);print(json.dumps({'parent':os.getpid(),'child':child.pid}),flush=True);time.sleep(30)"
        child = subprocess.Popen([sys.executable, '-c', runner], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, preexec_fn=phase.join_child)
        try:
            data = json.loads(child.stdout.readline())
            self.assertNotEqual(os.getsid(data['parent']), os.getsid(os.getpid()))
            self.assertEqual(child.wait(timeout=4), -9)
            phase.close()
            self.assertFalse(phase.directory.exists())
            self.assert_stopped(data['child'])
        finally:
            phase.close()
            child.stdout.close()
            child.stderr.close()

    def test_guardian_pipe_eof_cleans_group_when_supervisor_is_killed(self):
        runner = "import os,subprocess,sys,time,json;os.setsid();child=subprocess.Popen([sys.executable,'-c','import os,time;os.setsid();time.sleep(30)']);print(json.dumps({'parent':os.getpid(),'child':child.pid}),flush=True);time.sleep(30)"
        script = f'''
import json,pathlib,runpy,subprocess,sys,time,uuid
scope=runpy.run_path('scripts/studio/av-linux.py')
phase=scope['create_phase'](str(uuid.uuid4()),seconds=5)
child=subprocess.Popen([sys.executable,'-c',{runner!r}],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,preexec_fn=phase.join_child)
data=json.loads(child.stdout.readline())
data['directory']=str(phase.directory)
print(json.dumps(data),flush=True)
time.sleep(30)
'''
        supervisor = subprocess.Popen([sys.executable, '-B', '-c', script], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            data = json.loads(supervisor.stdout.readline())
            directory = pathlib.Path(data['directory'])
            self.assertTrue(directory.is_dir())
            supervisor.kill()
            supervisor.wait(timeout=2)
            until = time.monotonic() + 4
            while directory.exists() and time.monotonic() < until:
                time.sleep(0.025)
            self.assertFalse(directory.exists(), 'guardian did not remove its own subgroup after EOF')
            self.assert_stopped(data['parent'])
            self.assert_stopped(data['child'])
        finally:
            if supervisor.poll() is None:
                supervisor.kill()
                supervisor.wait(timeout=2)
            supervisor.stdout.close()
            supervisor.stderr.close()

    def test_invalid_namespace_or_deadline_fails_before_creating_a_group(self):
        create = module().create_phase
        for namespace in ['', '../escape', 'x' * 36, 5, True, None]:
            with self.subTest(namespace=namespace):
                with self.assertRaisesRegex(RuntimeError, 'runtime_unavailable'):
                    create(namespace)
        for seconds in [0, -1, 96, float('nan'), float('inf'), True, '5']:
            with self.subTest(seconds=seconds):
                with self.assertRaisesRegex(RuntimeError, 'runtime_unavailable'):
                    create(str(uuid.uuid4()), seconds=seconds)

    def test_supervisor_fallback_cleans_its_group_if_the_guardian_crashes(self):
        import signal
        phase = module().create_phase(str(uuid.uuid4()), seconds=5)
        child = subprocess.Popen([sys.executable, '-c', 'import time;time.sleep(30)'], preexec_fn=phase.join_child)
        try:
            os.kill(phase.guardian_pid, signal.SIGKILL)
            with self.assertRaisesRegex(RuntimeError, 'runtime_unavailable'):
                phase.close()
            self.assertFalse(phase.directory.exists())
            self.assertEqual(child.wait(timeout=2), -9)
        finally:
            if phase.directory.exists():
                phase._clean_group()
            child.wait(timeout=2)

    def test_closed_stdin_does_not_replace_the_private_guardian_pipe(self):
        script = '''
import os,runpy,time,uuid,json
scope=runpy.run_path('scripts/studio/av-linux.py')
os.close(0)
phase=scope['create_phase'](str(uuid.uuid4()),seconds=5)
time.sleep(0.2)
print(json.dumps({'reader':phase.reader,'writer':phase.writer,'alive':phase.directory.exists()}),flush=True)
phase.close()
'''
        result = subprocess.run([sys.executable, '-B', '-c', script], capture_output=True, text=True, timeout=4)
        self.assertEqual(result.returncode, 0, result.stderr)
        data = json.loads(result.stdout)
        self.assertTrue(data['alive'], data)
        self.assertGreaterEqual(data['reader'], 3)
        self.assertGreaterEqual(data['writer'], 3)

    def assert_stopped(self, pid):
        stat = pathlib.Path(f'/proc/{pid}/stat')
        if stat.exists():
            self.assertEqual(stat.read_text().split(') ', 1)[1].split()[0], 'Z', f'PID {pid} is still running')


@unittest.skipUnless(sys.platform == 'linux' and os.environ.get('STUDIO_AV_CGROUP_TEST') == '1', 'requires explicit delegated bounded Linux cgroup integration run')
class LinuxRuntimeDispatchTest(unittest.TestCase):
    def request(self, program, budget='85000'):
        # Process-contract fixture only: it does not encode media or claim native Linux MP4 acceptance.
        base = pathlib.Path('storage/framework/testing').resolve()
        with tempfile.TemporaryDirectory(prefix='av-linux-dispatch-', dir=base) as directory:
            binary = pathlib.Path(directory) / 'process-contract.py'
            binary.write_text('#!/usr/bin/python3\n'+program, encoding='utf-8')
            binary.chmod(0o700)
            namespace = str(uuid.uuid4())
            result = subprocess.run([sys.executable, '-B', 'scripts/studio/av-runtime.py', directory, namespace, 'plan', '0'],
                                    capture_output=True, text=True, timeout=8,
                                    env={**os.environ, 'STUDIO_NODE_BINARY': str(binary), 'STUDIO_AV_BUDGET_MS': budget})
            return namespace, result, json.loads(result.stdout)

    def test_dispatch_executes_only_inside_its_owned_cgroup_and_removes_it_before_return(self):
        namespace, result, data = self.request("import json,pathlib,runpy\nscope=runpy.run_path('scripts/studio/av-runtime.py')\nprint(json.dumps({'ok':True,'result':{'group':pathlib.Path('/proc/self/cgroup').read_text(),'memory':scope['observed_memory']()}}))\n")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(data['ok'], data)
        self.assertIn('studio-av-'+namespace, data['result']['group'])
        self.assertGreater(data['result']['memory']['limit'], 0)
        self.assertLessEqual(data['result']['memory']['limit'], 3 * 1024**3)
        root, _ = module().current_group()
        self.assertFalse((root/('studio-av-'+namespace)).exists())

    def test_dispatch_hard_timeout_kills_the_contract_process_and_returns_no_ready_result(self):
        start = time.monotonic()
        namespace, result, data = self.request('import time\ntime.sleep(30)\n', budget='100')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(data.get('error_code'), 'phase_timeout', data)
        self.assertLess(time.monotonic()-start, 6)
        root, _ = module().current_group()
        self.assertFalse((root/('studio-av-'+namespace)).exists())


if __name__ == '__main__':
    unittest.main()
