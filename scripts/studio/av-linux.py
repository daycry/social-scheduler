"""Delegated, owned cgroup lifecycle for a private audiovisual phase."""
import math
import os
import pathlib
import re
import select
import sys
import time


def current_group():
    membership = pathlib.Path('/proc/self/cgroup').read_text(encoding='utf-8')
    group = next((line[3:] for line in membership.splitlines() if line.startswith('0::')), None)
    if group is None:
        raise RuntimeError('runtime_unavailable')
    decode = lambda text: re.sub(r'\\([0-7]{3})', lambda match: chr(int(match[1], 8)), text)
    for line in pathlib.Path('/proc/self/mountinfo').read_text(encoding='utf-8').splitlines():
        before, separator, after = line.partition(' - ')
        fields = before.split()
        if not separator or after.split()[0] != 'cgroup2' or len(fields) < 5:
            continue
        mount_root, mount = pathlib.Path(decode(fields[3])), pathlib.Path(decode(fields[4]))
        try:
            relative = pathlib.Path(group).relative_to(mount_root)
        except ValueError:
            continue
        if '..' not in relative.parts:
            return mount / relative, mount
    raise RuntimeError('runtime_unavailable')


class Phase:
    def __init__(self, namespace, seconds):
        if sys.platform != 'linux' or os.getuid() == 0 or not isinstance(namespace, str) or re.fullmatch(r'[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}', namespace) is None:
            raise RuntimeError('runtime_unavailable')
        if type(seconds) not in (int, float) or not math.isfinite(seconds) or not 0.05 <= seconds <= 95:
            raise RuntimeError('runtime_unavailable')
        self.root, mount = current_group()
        if self.root.resolve() != self.root or self.root.stat().st_uid != os.getuid() or not os.access(self.root, os.W_OK):
            raise RuntimeError('runtime_unavailable')
        limits = []
        cursor = self.root
        while True:
            try:
                value = (cursor / 'memory.max').read_text(encoding='ascii').strip()
                if value != 'max':
                    value = int(value)
                    if value < 1:
                        raise RuntimeError('runtime_unavailable')
                    limits.append(value)
            except FileNotFoundError:
                pass  # Child groups may inherit accounting while their controller is inactive.
            if cursor == mount:
                break
            cursor = cursor.parent
        if not limits or min(limits) > 3 * 1024**3:
            raise RuntimeError('runtime_unavailable')
        self.limit = min(limits)
        self.directory = self.root / ('studio-av-' + namespace)
        self.supervisor_pid = os.getpid()
        self.closed = False
        self.directory.mkdir()  # Exclusive: never join or clean a previous phase's group.
        try:
            if not (self.directory / 'cgroup.kill').is_file() or not os.access(self.directory / 'cgroup.kill', os.W_OK):
                raise RuntimeError('runtime_unavailable')
            # An active delegated memory controller can impose a still tighter per-phase limit.
            if (self.directory / 'memory.max').exists():
                (self.directory / 'memory.max').write_text(str(self.limit), encoding='ascii')
                if int((self.directory / 'memory.max').read_text()) != self.limit:
                    raise RuntimeError('runtime_unavailable')
            self.reader, self.writer = os.pipe()
            try:
                # A daemon may start with fd0/1/2 closed. Keep both private endpoints away
                # from the guardian's standard-stream redirection before forking it.
                import fcntl
                for name in ('reader', 'writer'):
                    fd = getattr(self, name)
                    if fd < 3:
                        replacement = fcntl.fcntl(fd, fcntl.F_DUPFD_CLOEXEC, 3)
                        os.close(fd)
                        setattr(self, name, replacement)
                self.guardian_pid = os.fork()
            except BaseException:
                os.close(self.reader)
                os.close(self.writer)
                raise
        except BaseException:
            self.directory.rmdir()
            raise
        if self.guardian_pid == 0:
            os.close(self.writer)
            try:
                null = os.open(os.devnull, os.O_RDWR)
                for fd in (0, 1, 2):
                    os.dup2(null, fd)
                if null > 2:
                    os.close(null)
                select.select([self.reader], [], [], seconds)
                self._clean_group()
            except BaseException:
                os._exit(1)
            os._exit(0)
        os.close(self.reader)

    def join_child(self):
        # Runs in the newly forked process before exec, while no native code has started.
        (self.directory / 'cgroup.procs').write_text(str(os.getpid()), encoding='ascii')
        # If the parent died before migration, the guardian may already have killed an empty group.
        # Never exec after that race: a living parent implies guardian EOF has not happened yet.
        if os.getppid() != self.supervisor_pid:
            os._exit(125)
        os.close(self.writer)

    def _clean_group(self):
        if self.directory.parent != self.root or self.root.resolve() != self.root or self.directory.is_symlink():
            raise RuntimeError('runtime_unavailable')
        (self.directory / 'cgroup.kill').write_text('1', encoding='ascii')
        end = time.monotonic() + 2
        while True:
            events = dict(line.split() for line in (self.directory / 'cgroup.events').read_text(encoding='ascii').splitlines())
            if events.get('populated') == '0':
                self.directory.rmdir()
                return
            if time.monotonic() >= end:
                raise RuntimeError('runtime_unavailable')
            time.sleep(0.025)

    def close(self):
        if self.closed or os.getpid() != self.supervisor_pid:
            return
        self.closed = True
        try:
            os.write(self.writer, b'x')
        except BrokenPipeError:
            pass
        finally:
            os.close(self.writer)
        _, status = os.waitpid(self.guardian_pid, 0)
        if not os.WIFEXITED(status) or os.WEXITSTATUS(status) != 0 or self.directory.exists():
            if self.directory.exists():
                self._clean_group()  # A dead guardian cannot perform its normal cleanup; the owner still can.
            raise RuntimeError('runtime_unavailable')


def create_phase(namespace, seconds=90):
    return Phase(namespace, seconds)
