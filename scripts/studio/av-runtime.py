"""Private audiovisual process supervisor; no model imports or remote access."""
import json
import importlib.util
import os
import pathlib
import re
import subprocess
import sys
import tempfile
import time

LIMIT = 3 * 1024**3


def windows_api():
    import ctypes
    from ctypes import wintypes

    class Basic(ctypes.Structure):
        _fields_ = [('process_time', ctypes.c_longlong), ('job_time', ctypes.c_longlong),
                    ('flags', wintypes.DWORD), ('minimum', ctypes.c_size_t), ('maximum', ctypes.c_size_t),
                    ('active', wintypes.DWORD), ('affinity', ctypes.c_size_t),
                    ('priority', wintypes.DWORD), ('scheduling', wintypes.DWORD)]

    class Io(ctypes.Structure):
        _fields_ = [(name, ctypes.c_ulonglong) for name in ('read_ops', 'write_ops', 'other_ops', 'read_bytes', 'write_bytes', 'other_bytes')]

    class Bounds(ctypes.Structure):
        _fields_ = [('basic', Basic), ('io', Io), ('process_memory', ctypes.c_size_t),
                    ('job_memory', ctypes.c_size_t), ('peak_process_memory', ctypes.c_size_t), ('peak_job_memory', ctypes.c_size_t)]

    kernel = ctypes.WinDLL('kernel32', use_last_error=True)
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR]
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.GetCurrentProcess.argtypes = []
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.QueryInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD, ctypes.c_void_p]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    return ctypes, kernel, Bounds


def cgroup_bounds(membership, mountinfo, read):
    """Read the effective minimum across the real v2 membership and its mounted ancestors."""
    group = next((line[3:] for line in membership.splitlines() if line.startswith('0::')), None)
    if group is None:
        raise RuntimeError('runtime_unavailable')
    decode = lambda text: re.sub(r'\\([0-7]{3})', lambda match: chr(int(match[1], 8)), text)
    for line in mountinfo.splitlines():
        before, separator, after = line.partition(' - ')
        fields = before.split()
        if not separator or after.split()[0] != 'cgroup2' or len(fields) < 5:
            continue
        mount_root, mount = pathlib.PurePosixPath(decode(fields[3])), pathlib.PurePosixPath(decode(fields[4]))
        try:
            relative = pathlib.PurePosixPath(group).relative_to(mount_root)
        except ValueError:
            continue
        if '..' in relative.parts:
            raise RuntimeError('runtime_unavailable')
        current = mount / relative
        limits = []
        while True:
            try:
                text = read(str(current / 'memory.max')).strip()
                if text != 'max':
                    value = int(text)
                    if value < 1:
                        raise RuntimeError('runtime_unavailable')
                    limits.append(value)
            except FileNotFoundError:
                pass  # A valid v2 child can inherit its ancestor's controller without a local memory.max.
            if current == mount:
                break
            current = current.parent
        if not limits or min(limits) > LIMIT:
            raise RuntimeError('runtime_unavailable')
        return {'scope': 'cgroup-v2', 'limit': min(limits)}
    raise RuntimeError('runtime_unavailable')


def observed_memory(job=None):
    if os.name == 'nt':
        ctypes, kernel, Bounds = windows_api()
        bounds = Bounds()
        if not kernel.QueryInformationJobObject(job, 9, ctypes.byref(bounds), ctypes.sizeof(bounds), None):
            raise RuntimeError('runtime_unavailable')
        return {'scope': 'job', 'limit': bounds.job_memory if bounds.basic.flags & 0x200 else 0, 'peak_commit': bounds.peak_job_memory,
                'kill_on_close': bool(bounds.basic.flags & 0x2000), 'breakaway': bool(bounds.basic.flags & 0x1800)}
    if sys.platform == 'linux':
        read = lambda name: pathlib.Path(name).read_text(encoding='utf-8')
        return cgroup_bounds(read('/proc/self/cgroup'), read('/proc/self/mountinfo'), read)
    raise RuntimeError('runtime_unavailable')


def enforce_memory():
    if os.name != 'nt':
        # The dedicated service must already bound its entire descendant cgroup. Never use RLIMIT_AS for V8.
        observed_memory()
        return None
    ctypes, kernel, Bounds = windows_api()
    job = kernel.CreateJobObjectW(None, None)
    bounds = Bounds()
    bounds.basic.flags = 0x200 | 0x2000  # JOB_MEMORY | KILL_ON_JOB_CLOSE; no breakaway flags.
    bounds.job_memory = LIMIT
    if not job or not kernel.SetInformationJobObject(job, 9, ctypes.byref(bounds), ctypes.sizeof(bounds)) or not kernel.AssignProcessToJobObject(job, kernel.GetCurrentProcess()):
        raise RuntimeError('runtime_unavailable')
    observed = observed_memory(job)
    if observed['limit'] != LIMIT or not observed['kill_on_close'] or observed['breakaway']:
        raise RuntimeError('runtime_unavailable')
    return job  # Sole non-inheritable handle: OS close on supervisor exit kills every remaining descendant.


def observe_tree(depth):
    result = {'pid': os.getpid(), 'memory': observed_memory()}
    if depth:
        child = subprocess.run([sys.executable, str(pathlib.Path(__file__).resolve()), '--observe', str(depth - 1)],
                               capture_output=True, text=True, timeout=5)
        if child.returncode:
            raise RuntimeError('runtime_unavailable')
        result['child'] = json.loads(child.stdout)
    return result


def run_phase(arguments):
    if os.name != 'nt' and sys.platform != 'linux':
        raise RuntimeError('runtime_unavailable')
    if not pathlib.Path(arguments[0]).is_absolute() or re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', arguments[1]) is None or arguments[2] not in ('plan', 'normalize', 'render', 'mix', 'assemble') or re.fullmatch(r'[0-9]{1,16}', arguments[3]) is None or int(arguments[3]) > 9007199254740991:
        return {'ok': False, 'error_code': 'invalid_request'}
    budget = int(os.environ.get('STUDIO_AV_BUDGET_MS', '85000'))
    if not 100 <= budget <= 85000:
        return {'ok': False, 'error_code': 'invalid_request'}
    start = time.monotonic()
    env = os.environ.copy()
    env['STUDIO_AV_MANAGED'] = '1'
    env['STUDIO_AV_DEADLINE_MS'] = str(time.time_ns() // 1000000 + budget)
    guard = None
    if sys.platform == 'linux':
        spec = importlib.util.spec_from_file_location('_studio_av_linux', pathlib.Path(__file__).with_name('av-linux.py'))
        linux = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(linux)
        guard = linux.create_phase(arguments[1], seconds=(budget + 3000) / 1000)
    try:
        return supervise_native(arguments, env, budget, start, guard)
    finally:
        if guard:
            guard.close()  # Reap the guardian and remove only the owned empty group before accepting output.


def supervise_native(arguments, env, budget, start, guard):
    with tempfile.TemporaryFile() as stdout, tempfile.TemporaryFile() as stderr:
        child = subprocess.Popen([os.environ.get('STUDIO_NODE_BINARY', 'node'), str(pathlib.Path(__file__).with_name('render-av.mjs')), *arguments],
                                 stdout=stdout, stderr=stderr, env=env, close_fds=True,
                                 **({'creationflags': subprocess.CREATE_NO_WINDOW} if os.name == 'nt' else {'preexec_fn': guard.join_child}))
        failure = None
        try:
            while child.poll() is None:
                if time.monotonic() - start > (budget + 3000) / 1000:
                    failure = 'phase_timeout'
                    break
                if os.fstat(stdout.fileno()).st_size > 8 * 1024**2 or os.fstat(stderr.fileno()).st_size > 65536:
                    failure = 'output_budget'
                    break
                time.sleep(0.025)
            if failure:
                child.kill()
                child.wait(timeout=2)
                return {'ok': False, 'error_code': failure}
            stdout.seek(0)
            raw = stdout.read(8 * 1024**2 + 1)
            if len(raw) > 8 * 1024**2 or os.fstat(stderr.fileno()).st_size > 65536:
                return {'ok': False, 'error_code': 'output_budget'}
            if time.monotonic() - start > budget / 1000:
                return {'ok': False, 'error_code': 'phase_timeout', 'memory': observed_memory()}
            response = json.loads(raw)
            if not isinstance(response, dict) or not isinstance(response.get('ok'), bool) or (response['ok'] and child.returncode):
                raise RuntimeError('runtime_unavailable')
            response['memory'] = observed_memory()
            return response
        finally:
            if child.poll() is None:
                child.kill()
                child.wait(timeout=2)


def main():
    try:
        if sys.argv[1:] == ['--probe']:
            memory_job = enforce_memory()
            response = {'ok': True, **observe_tree(2)}
        elif len(sys.argv) == 3 and sys.argv[1] == '--observe' and sys.argv[2] in ('0', '1'):
            response = observe_tree(int(sys.argv[2]))
        elif len(sys.argv) == 5:
            memory_job = enforce_memory()
            response = run_phase(sys.argv[1:])
        else:
            response = {'ok': False, 'error_code': 'invalid_request'}
    except (RuntimeError, OSError, ValueError, subprocess.SubprocessError):
        response = {'ok': False, 'error_code': 'runtime_unavailable'}
    print(json.dumps(response), flush=True)
    return 0 if response.get('ok', 'memory' in response) else 2


if __name__ == '__main__':
    raise SystemExit(main())
