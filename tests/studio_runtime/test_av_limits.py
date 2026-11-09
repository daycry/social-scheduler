import json
import os
import subprocess
import sys
import unittest


@unittest.skipUnless(os.name == 'nt', 'Windows Job Object acceptance; Linux needs its real bounded worker cgroup')
class AudiovisualLimitsTest(unittest.TestCase):
    def test_probe_observes_three_gib_job_limit_in_parent_child_and_grandchild(self):
        result = subprocess.run([sys.executable, 'scripts/studio/av-runtime.py', '--probe'], capture_output=True, text=True, timeout=15)
        data = json.loads(result.stdout)
        self.assertTrue(data['ok'], data)
        self.assertEqual(result.returncode, 0, result.stderr)
        for process in (data['memory'], data['child']['memory'], data['child']['child']['memory']):
            self.assertEqual(process['limit'], 3 * 1024**3)
            self.assertEqual(process['scope'], 'job')
            self.assertTrue(process['kill_on_close'])
            self.assertFalse(process['breakaway'])
        self.assertNotEqual(data['pid'], data['child']['pid'])
        self.assertNotEqual(data['child']['pid'], data['child']['child']['pid'])

    def test_supervisor_exit_closes_its_job_and_terminates_a_detached_child(self):
        script = '''
import os,runpy,subprocess,sys
scope=runpy.run_path('scripts/studio/av-runtime.py')
job=scope['enforce_memory']()
child=subprocess.Popen([sys.executable,'-c','import time;time.sleep(15)'],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=subprocess.DETACHED_PROCESS|subprocess.CREATE_NEW_PROCESS_GROUP)
print(child.pid,flush=True)
'''
        result = subprocess.run([sys.executable, '-c', script], capture_output=True, text=True, timeout=10)
        self.assertEqual(result.returncode, 0, result.stderr)
        pid = int(result.stdout.strip())
        import ctypes
        from ctypes import wintypes
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.OpenProcess.argtypes = [wintypes.DWORD, wintypes.BOOL, wintypes.DWORD]
        kernel.OpenProcess.restype = wintypes.HANDLE
        kernel.WaitForSingleObject.argtypes = [wintypes.HANDLE, wintypes.DWORD]
        kernel.CloseHandle.argtypes = [wintypes.HANDLE]
        process = kernel.OpenProcess(0x100000, False, pid)
        if process:
            try:
                self.assertEqual(kernel.WaitForSingleObject(process, 3000), 0, 'detached child survived supervisor exit')
            finally:
                kernel.CloseHandle(process)
        else:
            self.assertEqual(ctypes.get_last_error(), 87, 'only an already vanished PID is accepted')


class CgroupBoundsTest(unittest.TestCase):
    def test_child_without_local_memory_controller_uses_its_bounded_ancestor(self):
        import runpy
        bounds = runpy.run_path('scripts/studio/av-runtime.py')['cgroup_bounds']
        files = {'/sys/fs/cgroup/service/memory.max': str(3 * 1024**3), '/sys/fs/cgroup/memory.max': 'max'}
        def read(name):
            if name not in files:
                raise FileNotFoundError(name)
            return files[name]
        self.assertEqual(bounds('0::/service/phase\n', '20 19 0:29 / /sys/fs/cgroup rw - cgroup2 cgroup rw\n', read), {'scope': 'cgroup-v2', 'limit': 3 * 1024**3})

    def test_effective_ancestor_limit_is_used_and_unbounded_or_unmounted_groups_fail_closed(self):
        import runpy
        bounds = runpy.run_path('scripts/studio/av-runtime.py')['cgroup_bounds']
        membership = '0::/service/worker\n'
        mount = '20 19 0:29 / /sys/fs/cgroup rw - cgroup2 cgroup rw\n'
        files = {'/sys/fs/cgroup/service/worker/memory.max': 'max', '/sys/fs/cgroup/service/memory.max': str(2 * 1024**3), '/sys/fs/cgroup/memory.max': 'max'}
        self.assertEqual(bounds(membership, mount, files.__getitem__), {'scope': 'cgroup-v2', 'limit': 2 * 1024**3})
        files['/sys/fs/cgroup/service/memory.max'] = 'max'
        with self.assertRaisesRegex(RuntimeError, 'runtime_unavailable'):
            bounds(membership, mount, files.__getitem__)
        with self.assertRaisesRegex(RuntimeError, 'runtime_unavailable'):
            bounds('0::/outside\n', mount.replace('/ /sys', '/service /sys'), files.__getitem__)


if __name__ == '__main__':
    unittest.main()
