<?php
declare(strict_types=1);

class DB {
    private static ?self $instance = null;
    private array $cache = [];

    public static function connect(): self {
        return self::$instance ??= new self();
    }

    private function file(string $table): string {
        return DATA_DIR . '/' . $table . '.json';
    }

    private function load(string $table): array {
        if (!isset($this->cache[$table])) {
            $f = $this->file($table);
            $this->cache[$table] = (is_file($f) ? (json_decode(file_get_contents($f), true) ?? []) : []);
        }
        return $this->cache[$table];
    }

    private function save(string $table): void {
        file_put_contents($this->file($table), json_encode($this->cache[$table] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function all(string $table): array {
        return $this->load($table);
    }

    public function find(string $table, string $column, mixed $value): array {
        return array_values(array_filter($this->load($table), fn($r) => ($r[$column] ?? null) == $value));
    }

    public function findOne(string $table, string $column, mixed $value): ?array {
        foreach ($this->load($table) as $r) {
            if (($r[$column] ?? null) == $value) return $r;
        }
        return null;
    }

    public function findFirst(string $table, array $conditions): ?array {
        foreach ($this->load($table) as $r) {
            $match = true;
            foreach ($conditions as $col => $val) {
                if (($r[$col] ?? null) != $val) { $match = false; break; }
            }
            if ($match) return $r;
        }
        return null;
    }

    public function findAll(string $table, array $conditions): array {
        return array_values(array_filter($this->load($table), function($r) use ($conditions) {
            foreach ($conditions as $col => $val) {
                if (($r[$col] ?? null) != $val) return false;
            }
            return true;
        }));
    }

    public function insert(string $table, array $data): int {
        $this->load($table);
        $maxId = 0;
        foreach ($this->cache[$table] as $r) {
            if (($r['id'] ?? 0) > $maxId) $maxId = $r['id'];
        }
        $data['id'] = $maxId + 1;
        $this->cache[$table][] = $data;
        $this->save($table);
        return $data['id'];
    }

    public function update(string $table, string $column, mixed $value, array $data): int {
        $this->load($table);
        $count = 0;
        foreach ($this->cache[$table] as &$r) {
            if (($r[$column] ?? null) == $value) {
                foreach ($data as $k => $v) $r[$k] = $v;
                $count++;
            }
        }
        unset($r);
        if ($count) $this->save($table);
        return $count;
    }

    public function setTable(string $table, array $data): void {
        $this->cache[$table] = $data;
        $this->save($table);
    }

    public function delete(string $table, string $column, mixed $value): int {
        $rows = $this->load($table);
        $before = count($rows);
        $rows = array_values(array_filter($rows, fn($r) => ($r[$column] ?? null) != $value));
        $removed = $before - count($rows);
        if ($removed) {
            $this->cache[$table] = $rows;
            $this->save($table);
        }
        return $removed;
    }

    public function count(string $table): int {
        return count($this->load($table));
    }

    public function initSchema(): void {
        foreach (['beacons','tasks','results','files','media','browse_cache','persons','users','login_attempts','payloads'] as $t) {
            $this->load($t);
        }
    }
}
