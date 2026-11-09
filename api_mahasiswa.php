<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$dataFile = 'data_presensi_lokal.json';

// Jika file JSON lokal belum ada, buat file kosong
if (!file_exists($dataFile)) {
    file_put_contents($dataFile, json_encode([]));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $data = json_decode(file_get_contents($dataFile), true);
    echo json_encode($data ? $data : []);
    exit;
} 

if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!$input) {
        echo json_encode(['status' => 'error', 'message' => 'Format data tidak valid']);
        exit;
    }

    $currentData = json_decode(file_get_contents($dataFile), true);
    if (!is_array($currentData)) {
        $currentData = [];
    }

    // Aksi Hapus Data
    if (isset($input['action']) && $input['action'] == 'delete') {
        $idToDelete = $input['id'] ?? '';
        if (isset($currentData[$idToDelete])) {
            unset($currentData[$idToDelete]);
            file_put_contents($dataFile, json_encode($currentData, JSON_PRETTY_PRINT));
            echo json_encode(['status' => 'success', 'message' => 'Data berhasil dihapus']);
            exit;
        }
    } 
    // Aksi Update / Edit Data
    elseif (isset($input['id']) && !empty($input['id'])) {
        $id = $input['id'];
        unset($input['id']);
        $currentData[$id] = $input;
        file_put_contents($dataFile, json_encode($currentData, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'message' => 'Data berhasil diupdate']);
        exit;
    } 
    // Aksi Tambah Data Baru (Input Presensi)
    else {
        $newId = 'mhs_' . uniqid();
        $newData = [
            'nim' => $input['nim'] ?? '-',
            'nama' => $input['nama'] ?? '-',
            'mataKuliah' => $input['mataKuliah'] ?? '-',
            'tanggal' => $input['tanggal'] ?? date('Y-m-d'),
            'status' => $input['status'] ?? 'Hadir',
            'catatan' => $input['catatan'] ?? '-',
            'createdAt' => date('Y-m-d H:i:s')
        ];

        $currentData[$newId] = $newData;
        file_put_contents($dataFile, json_encode($currentData, JSON_PRETTY_PRINT));
        
        echo json_encode(['status' => 'success', 'message' => 'Data presensi berhasil disimpan']);
        exit;
    }
}
?>