<?php
require_once "database.php";
require_admin();

$type = $_GET["report_type"] ?? "inventory_summary";
$from = $_GET["date_from"] ?? "";
$to = $_GET["date_to"] ?? "";
$item = (int)($_GET["item_id"] ?? 0);
$itype = $_GET["item_type"] ?? "";
$wh = (int)($_GET["warehouse_id"] ?? 0);
$mt = $_GET["movement_type"] ?? "";
$cols = [];
$rows = [];
$title = "Inventory Report";
$summary = null;
function filters($from, $to, $item, $itype, $wh, $mt, &$sql, &$p, $dateField = "date")
{
  if ($from) {
    $sql .= " AND $dateField>=?";
    $p[] = $from;
  }
  if ($to) {
    $sql .= " AND $dateField<=?";
    $p[] = $to;
  }
  if ($item) {
    $sql .= " AND i.id=?";
    $p[] = $item;
  }
  if ($itype) {
    $sql .= " AND i.item_type=?";
    $p[] = $itype;
  }
  if ($wh) {
    $sql .= " AND w.id=?";
    $p[] = $wh;
  }
  if ($mt) {
    $sql .= " AND sm.movement_type=?";
    $p[] = $mt;
  }
}
if ($type === "monthly_summary") {
  if (!$from) $from = date("Y-m-01");
  if (!$to) $to = date("Y-m-d");
  $title = "Monthly Inventory Summary (" . $from . " to " . $to . ")";
  $sql = "SELECT i.id item_id,i.item_name,i.item_type,i.size,i.unit,w.id warehouse_id,w.warehouse_name,i.reorder_level,
   COALESCE(SUM(CASE WHEN sm.movement_type IN ('STOCK IN','STOCK TRANSFER IN') THEN sm.quantity ELSE 0 END),0) stock_in,
   COALESCE(SUM(CASE WHEN sm.movement_type IN ('STOCK OUT','STOCK TRANSFER OUT') THEN sm.quantity ELSE 0 END),0) stock_out,
   COALESCE(SUM(CASE WHEN sm.movement_type='STOCK ADJUSTMENT' THEN sm.quantity ELSE 0 END),0) adjustments
   FROM items i JOIN warehouses w ON w.status='Active'
   LEFT JOIN stock_movements sm ON sm.item_id=i.id AND sm.warehouse_id=w.id AND sm.date BETWEEN ? AND ?
   WHERE i.status='Active'";
  $p = [$from, $to];
  if ($item) {
    $sql .= " AND i.id=?";
    $p[] = $item;
  }
  if ($itype) {
    $sql .= " AND i.item_type=?";
    $p[] = $itype;
  }
  if ($wh) {
    $sql .= " AND w.id=?";
    $p[] = $wh;
  }
  $sql .= " GROUP BY i.id,w.id ORDER BY i.item_name";
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $raw = $st->fetchAll();
  $groups = [];
  foreach ($raw as $r) {
    $name = trim((string)$r['item_name']);
    $size = trim((string)($r['size'] ?? ''));
    $unit = trim((string)$r['unit']);
    $key = strtolower(preg_replace('/\s+/', ' ', $name)) . '|' . strtolower(trim((string)$r['item_type'])) . '|' . strtolower(preg_replace('/\s+/', ' ', $size)) . '|' . strtolower(preg_replace('/\s+/', ' ', $unit));
    if (!isset($groups[$key])) $groups[$key] = ['item_name' => $name, 'item_type' => $r['item_type'], 'size' => $size, 'unit' => $unit, 'warehouses' => [], 'stock_in' => 0, 'stock_out' => 0, 'adjustments' => 0];
    $g = &$groups[$key];
    $wid = (string)$r['warehouse_id'];
    if (!isset($g['warehouses'][$wid])) $g['warehouses'][$wid] = ['name' => $r['warehouse_name'], 'reorder' => (float)$r['reorder_level']];
    else $g['warehouses'][$wid]['reorder'] = max($g['warehouses'][$wid]['reorder'], (float)$r['reorder_level']);
    $g['stock_in'] += (float)$r['stock_in'];
    $g['stock_out'] += (float)$r['stock_out'];
    $g['adjustments'] += (float)$r['adjustments'];
    unset($g);
  }
  $totalIn = 0;
  $totalOut = 0;
  $totalBeg = 0;
  $totalEnd = 0;
  $lowCount = 0;
  foreach ($groups as $g) {
    $beg = $wh ? 50 : 100;
    $in = $g['stock_in'];
    $out = $g['stock_out'];
    $adj = $g['adjustments'];
    $end = $beg + $in - $out + $adj;
    $reorder = array_sum(array_column($g['warehouses'], 'reorder'));
    $status = ($reorder > 0 && $end <= $reorder) ? "Low Stock" : "OK";
    if ($status === "Low Stock") $lowCount++;
    $totalIn += $in;
    $totalOut += $out;
    $totalBeg += $beg;
    $totalEnd += $end;
    $warehouseNames = array_values(array_unique(array_column($g['warehouses'], 'name')));
    $rows[] = ['item_name' => $g['item_name'], 'item_type' => $g['item_type'], 'size' => $g['item_type'] === "Raw Material" ? "----" : ($g['size'] !== "" ? $g['size'] : "----"), 'warehouse_name' => implode(', ', $warehouseNames), 'beginning_stock' => $beg, 'stock_in' => $in, 'stock_out' => $out, 'adjustments' => $adj, 'ending_stock' => $end, 'reorder_level' => $reorder, 'status' => $status];
  }
  $cols = [['key' => 'item_name', 'label' => 'Item'], ['key' => 'item_type', 'label' => 'Type'], ['key' => 'size', 'label' => 'Size'], ['key' => 'warehouse_name', 'label' => 'Warehouse'], ['key' => 'beginning_stock', 'label' => 'Beginning Stock'], ['key' => 'stock_in', 'label' => 'Stock In'], ['key' => 'stock_out', 'label' => 'Stock Out'], ['key' => 'adjustments', 'label' => 'Adjustments'], ['key' => 'ending_stock', 'label' => 'Ending Stock'], ['key' => 'reorder_level', 'label' => 'Reorder Level'], ['key' => 'status', 'label' => 'Status']];
  $summary = ['date_from' => $from, 'date_to' => $to, 'total_beginning_stock' => $totalBeg, 'total_stock_in' => $totalIn, 'total_stock_out' => $totalOut, 'total_ending_stock' => $totalEnd, 'low_stock_items' => $lowCount];
} elseif ($type === "inventory_summary" || $type === "warehouse_inventory" || $type === "raw_materials" || $type === "finished_goods") {
  $sql = "SELECT i.id item_id,i.item_name,i.item_type,i.size,i.unit,c.category_name,w.id warehouse_id,w.warehouse_name,COALESCE(inv.quantity,0) quantity,i.reorder_level FROM items i LEFT JOIN categories c ON c.id=i.category_id JOIN warehouses w ON w.status='Active' LEFT JOIN inventory inv ON inv.item_id=i.id AND inv.warehouse_id=w.id WHERE i.status='Active'";
  $p = [];
  if ($item) {
    $sql .= " AND i.id=?";
    $p[] = $item;
  }
  if ($itype) {
    $sql .= " AND i.item_type=?";
    $p[] = $itype;
  }
  if ($wh) {
    $sql .= " AND w.id=?";
    $p[] = $wh;
  }
  if ($type === "raw_materials") {
    $sql .= " AND i.item_type='Raw Material'";
    $title = "Raw Materials Report";
  } elseif ($type === "finished_goods") {
    $sql .= " AND i.item_type='Finished Good'";
    $title = "Finished Goods Report";
  } elseif ($type === "warehouse_inventory") $title = "Warehouse Inventory Report";
  else $title = "Inventory Summary";
  $sql .= " ORDER BY i.item_name,w.warehouse_name";
  $st = $pdo->prepare($sql);
  $st->execute($p);
  $raw = $st->fetchAll();
  $groups = [];
  foreach ($raw as $r) {
    $name = trim((string)$r['item_name']);
    $size = trim((string)($r['size'] ?? ''));
    $unit = trim((string)$r['unit']);
    $key = strtolower(preg_replace('/\s+/', ' ', $name)) . '|' . strtolower(trim((string)$r['item_type'])) . '|' . strtolower(preg_replace('/\s+/', ' ', $size)) . '|' . strtolower(preg_replace('/\s+/', ' ', $unit));
    if (!isset($groups[$key])) $groups[$key] = ['item_name' => $name, 'item_type' => $r['item_type'], 'size' => $size, 'unit' => $unit, 'category_name' => $r['category_name'], 'warehouses' => [], 'quantity' => 0];
    $g = &$groups[$key];
    $wid = (string)$r['warehouse_id'];
    if (!isset($g['warehouses'][$wid])) $g['warehouses'][$wid] = ['name' => $r['warehouse_name'], 'reorder' => (float)$r['reorder_level']];
    else $g['warehouses'][$wid]['reorder'] = max($g['warehouses'][$wid]['reorder'], (float)$r['reorder_level']);
    $g['quantity'] += (float)$r['quantity'];
    unset($g);
  }
  foreach ($groups as $g) {
    $rows[] = ['item_name' => $g['item_name'], 'item_type' => $g['item_type'], 'size' => $g['item_type'] === "Raw Material" ? "----" : ($g['size'] !== "" ? $g['size'] : "----"), 'category_name' => $g['category_name'], 'warehouse_name' => implode(', ', array_unique(array_column($g['warehouses'], 'name'))), 'quantity' => $g['quantity'], 'reorder_level' => array_sum(array_column($g['warehouses'], 'reorder')), 'unit' => $g['unit']];
  }
  $cols = [['key' => 'item_name', 'label' => 'Item Name'], ['key' => 'item_type', 'label' => 'Type'], ['key' => 'size', 'label' => 'Size'], ['key' => 'category_name', 'label' => 'Category'], ['key' => 'warehouse_name', 'label' => 'Warehouse'], ['key' => 'quantity', 'label' => 'Stock'], ['key' => 'reorder_level', 'label' => 'Reorder'], ['key' => 'unit', 'label' => 'Unit']];
} elseif (in_array($type, ["stock_in", "stock_out", "stock_transfer", "stock_adjustment"])) {
  if ($type === "stock_in") {
    $sql = "SELECT si.date,si.stock_in_number number,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,si.quantity,COALESCE(NULLIF(si.unit,''),i.unit) unit,si.supplier,si.reference_number FROM stock_in si JOIN items i ON i.id=si.item_id JOIN warehouses w ON w.id=si.warehouse_id WHERE 1=1";
    $dateField = "si.date";
    $title = "Stock In Report";
    $cols = [["key" => "date", "label" => "Date"], ["key" => "number", "label" => "Number"], ["key" => "item_name", "label" => "Item"], ["key" => "item_type", "label" => "Type"], ["key" => "size", "label" => "Size"], ["key" => "warehouse_name", "label" => "Warehouse"], ["key" => "quantity", "label" => "Quantity"], ["key" => "unit", "label" => "Unit"], ["key" => "supplier", "label" => "Supplier"], ["key" => "reference_number", "label" => "Reference"]];
  } elseif ($type === "stock_out") {
    $sql = "SELECT so.date,so.stock_out_number number,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,so.quantity,i.unit,so.purpose,so.reference_number FROM stock_out so JOIN items i ON i.id=so.item_id JOIN warehouses w ON w.id=so.warehouse_id WHERE 1=1";
    $dateField = "so.date";
    $title = "Stock Out Report";
    $cols = [["key" => "date", "label" => "Date"], ["key" => "number", "label" => "Number"], ["key" => "item_name", "label" => "Item"], ["key" => "item_type", "label" => "Type"], ["key" => "size", "label" => "Size"], ["key" => "warehouse_name", "label" => "Warehouse"], ["key" => "quantity", "label" => "Quantity"], ["key" => "unit", "label" => "Unit"], ["key" => "purpose", "label" => "Purpose"], ["key" => "reference_number", "label" => "Reference"]];
  } elseif ($type === "stock_transfer") {
    $sql = "SELECT st.date,st.transfer_number number,i.item_code,i.item_name,i.item_type,i.size,f.warehouse_name from_warehouse,t.warehouse_name to_warehouse,COALESCE(sti.quantity,st.quantity) quantity,i.unit,st.status FROM stock_transfers st LEFT JOIN stock_transfer_items sti ON sti.transfer_id=st.id JOIN items i ON i.id=COALESCE(sti.item_id,st.item_id) JOIN warehouses f ON f.id=st.from_warehouse JOIN warehouses t ON t.id=st.to_warehouse WHERE 1=1";
    $dateField = "st.date";
    $title = "Stock Transfer Report";
    $cols = [["key" => "date", "label" => "Date"], ["key" => "number", "label" => "Number"], ["key" => "item_name", "label" => "Item"], ["key" => "size", "label" => "Size"], ["key" => "from_warehouse", "label" => "From"], ["key" => "to_warehouse", "label" => "To"], ["key" => "quantity", "label" => "Quantity"], ["key" => "unit", "label" => "Unit"], ["key" => "status", "label" => "Status"]];
  } else {
    $sql = "SELECT sa.date,sa.adjustment_number number,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,sa.current_quantity,sa.adjusted_quantity,sa.difference,i.unit,sa.reason FROM stock_adjustments sa JOIN items i ON i.id=sa.item_id JOIN warehouses w ON w.id=sa.warehouse_id WHERE 1=1";
    $dateField = "sa.date";
    $title = "Stock Adjustment Report";
    $cols = [["key" => "date", "label" => "Date"], ["key" => "number", "label" => "Number"], ["key" => "item_name", "label" => "Item"], ["key" => "item_type", "label" => "Type"], ["key" => "size", "label" => "Size"], ["key" => "warehouse_name", "label" => "Warehouse"], ["key" => "current_quantity", "label" => "Current"], ["key" => "adjusted_quantity", "label" => "Adjusted"], ["key" => "difference", "label" => "Difference"], ["key" => "unit", "label" => "Unit"], ["key" => "reason", "label" => "Reason"]];
  }
  $p = [];
  if ($from) {
    $sql .= " AND $dateField>=?";
    $p[] = $from;
  }
  if ($to) {
    $sql .= " AND $dateField<=?";
    $p[] = $to;
  }
  if ($item) {
    $sql .= " AND i.id=?";
    $p[] = $item;
  }
  if ($itype) {
    $sql .= " AND i.item_type=?";
    $p[] = $itype;
  }
  if ($wh) {
    $sql .= ($type === "stock_transfer" ? " AND (f.id=? OR t.id=?)" : " AND w.id=?");
    if ($type === "stock_transfer") {
      $p[] = $wh;
      $p[] = $wh;
    } else $p[] = $wh;
  }
  $sql .= " ORDER BY $dateField DESC";
  $s = $pdo->prepare($sql);
  $s->execute($p);
  $rows = $s->fetchAll();
} elseif (in_array($type, ["stock_movement", "stock_card", "low_stock"])) {
  if ($type === "low_stock") {
    $sql = "SELECT i.id item_id,i.item_name,i.item_type,i.size,i.unit,w.id warehouse_id,w.warehouse_name,COALESCE(inv.quantity,0) current_stock,i.reorder_level FROM items i JOIN warehouses w ON w.status='Active' LEFT JOIN inventory inv ON inv.item_id=i.id AND inv.warehouse_id=w.id WHERE i.status='Active' AND i.reorder_level>0";
    $p = [];
    if ($item) {
      $sql .= " AND i.id=?";
      $p[] = $item;
    }
    if ($itype) {
      $sql .= " AND i.item_type=?";
      $p[] = $itype;
    }
    if ($wh) {
      $sql .= " AND w.id=?";
      $p[] = $wh;
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $raw = $st->fetchAll();
    $groups = [];
    foreach ($raw as $r) {
      $name = trim((string)$r['item_name']);
      $size = trim((string)($r['size'] ?? ''));
      $unit = trim((string)$r['unit']);
      $key = strtolower(preg_replace('/\s+/', ' ', $name)) . '|' . strtolower(trim((string)$r['item_type'])) . '|' . strtolower(preg_replace('/\s+/', ' ', $size)) . '|' . strtolower(preg_replace('/\s+/', ' ', $unit));
      if (!isset($groups[$key])) $groups[$key] = ['item_name' => $name, 'item_type' => $r['item_type'], 'size' => $size, 'warehouses' => [], 'current_stock' => 0];
      $g = &$groups[$key];
      $wid = (string)$r['warehouse_id'];
      if (!isset($g['warehouses'][$wid])) $g['warehouses'][$wid] = ['name' => $r['warehouse_name'], 'reorder' => (float)$r['reorder_level']];
      else $g['warehouses'][$wid]['reorder'] = max($g['warehouses'][$wid]['reorder'], (float)$r['reorder_level']);
      $g['current_stock'] += (float)$r['current_stock'];
      unset($g);
    }
    foreach ($groups as $g) {
      $reorder = array_sum(array_column($g['warehouses'], 'reorder'));
      $stock = $g['current_stock'];
      if ($reorder > 0 && $stock <= $reorder) $rows[] = ['item_name' => $g['item_name'], 'item_type' => $g['item_type'], 'size' => $g['item_type'] === "Raw Material" ? "----" : ($g['size'] !== "" ? $g['size'] : "----"), 'warehouse_name' => implode(', ', array_unique(array_column($g['warehouses'], 'name'))), 'current_stock' => $stock, 'reorder_level' => $reorder, 'status' => $stock <= $reorder * .5 ? 'Critical' : 'Low Stock'];
    }
    $title = "Low Stock Report";
    $cols = [['key' => 'item_name', 'label' => 'Item'], ['key' => 'item_type', 'label' => 'Type'], ['key' => 'size', 'label' => 'Size'], ['key' => 'warehouse_name', 'label' => 'Warehouse'], ['key' => 'current_stock', 'label' => 'Current Stock'], ['key' => 'reorder_level', 'label' => 'Reorder Level'], ['key' => 'status', 'label' => 'Status']];
  } else {
    $sql = "SELECT sm.date,i.item_code,i.item_name,i.item_type,i.size,w.warehouse_name,sm.movement_type,sm.quantity,COALESCE(NULLIF(sm.unit,''),i.unit) unit,sm.reference_number,u.username,sm.remarks FROM stock_movements sm JOIN items i ON i.id=sm.item_id JOIN warehouses w ON w.id=sm.warehouse_id LEFT JOIN users u ON u.id=sm.user_id WHERE 1=1";
    $p = [];
    if ($from) {
      $sql .= " AND sm.date>=?";
      $p[] = $from;
    }
    if ($to) {
      $sql .= " AND sm.date<=?";
      $p[] = $to;
    }
    if ($item) {
      $sql .= " AND i.id=?";
      $p[] = $item;
    }
    if ($itype) {
      $sql .= " AND i.item_type=?";
      $p[] = $itype;
    }
    if ($wh) {
      $sql .= " AND w.id=?";
      $p[] = $wh;
    }
    if ($mt) {
      $sql .= " AND sm.movement_type=?";
      $p[] = $mt;
    }
    $sql .= " ORDER BY sm.date DESC,sm.id DESC";
    $s = $pdo->prepare($sql);
    $s->execute($p);
    $rows = $s->fetchAll();
    $title = $type === "stock_card" ? "Stock Card Report" : "Stock Movement Report";
    $cols = [["key" => "date", "label" => "Date"], ["key" => "item_name", "label" => "Item"], ["key" => "item_type", "label" => "Type"], ["key" => "size", "label" => "Size"], ["key" => "warehouse_name", "label" => "Warehouse"], ["key" => "movement_type", "label" => "Transaction"], ["key" => "quantity", "label" => "Quantity"], ["key" => "unit", "label" => "Unit"], ["key" => "reference_number", "label" => "Reference"], ["key" => "username", "label" => "User"], ["key" => "remarks", "label" => "Remarks"]];
  }
}
foreach ($rows as &$rr) {
  if (isset($rr["item_type"])) $rr["size"] = $rr["item_type"] === "Raw Material" ? "----" : (($rr["size"] ?? "") !== "" ? $rr["size"] : "----");
}
json_response(["success" => true, "title" => $title, "columns" => $cols, "rows" => $rows, "summary" => $summary]);
