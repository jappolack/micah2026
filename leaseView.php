<?php
    // Template for new VMS pages. Base your new page on this one

    // Make session information accessible, allowing us to associate
    // data with the logged-in user.
    session_cache_expire(30);
    session_start();

    $loggedIn = false;
    $accessLevel = 0;
    $userID = null;
    if (isset($_SESSION['_id'])) {
        $loggedIn = true;
        // 0 = not logged in, 1 = standard user, 2 = manager (Admin), 3 super admin (TBI)
        $accessLevel = $_SESSION['access_level'];
        $userID = $_SESSION['_id'];
    }
    // admin-only access
    if ($accessLevel < 2) {
        header('Location: index.php');
        die();
    }

    // include database functions
    require_once('database/dbinfo.php');
    
    // pagination and sorting settings
    $items_per_page = 15;
    $current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    if ($current_page < 1) { $current_page = 1; }
    $offset = ($current_page - 1) * $items_per_page;
    
    // sorting parameters - validate to prevent sql injection
    $allowed_sort_columns = [
        'id','tenant_first_name','tenant_last_name','property_street','property_city',
        'property_state','unit_number','start_date','expiration_date','monthly_rent',
        'security_deposit','program_type','status','created_at','updated_at'
    ];
    $sort_by = $_GET['sort'] ?? 'created_at';
    if (!in_array($sort_by, $allowed_sort_columns)) {
        $sort_by = 'created_at';
    }
    
    $sort_order = strtoupper($_GET['order'] ?? 'DESC');
    if (!in_array($sort_order, ['ASC','DESC'])) {
        $sort_order = 'DESC';
    }

    // NEW: month filter (1..12). Keep empty => all months
    $exp_month = $_GET['exp_month'] ?? '';
    $month_int = null;
    if ($exp_month !== '' && ctype_digit($exp_month)) {
        $m = (int)$exp_month;
        if ($m >= 1 && $m <= 12) { $month_int = $m; }
    }

    //day filter
    $exp_day = $_GET['exp_day'] ?? '';
    $day_int = null;
    if($exp_day !== '' && ctype_digit($exp_day)){
        $d = (int)$exp_day;
        if($d >= 1 && $d <= 31) {$day_int = $d;}
    }

    /*
    // Build WHERE for month filter (and now day as well)

    if ($month_int !== null && $day_int !== null) {
    $where = " WHERE MONTH(expiration_date) = $month_int AND DAY(expiration_date) = $day_int";
    } */

    $where = '';

    if ($month_int !== null && $day_int !== null) {
        // Filter by exact month + day
        $where = " WHERE MONTH(expiration_date) = $month_int AND DAY(expiration_date) = $day_int";
    }
    else if ($month_int !== null) {
        // Filter by month only
        $where = " WHERE MONTH(expiration_date) = $month_int";
    }



    $lease_date = null;
    if($month_int !== null && $day_int !== null){
        $year = date('Y');
        $lease_date = sprintf('%04d-%02d-%02d', $year, $month_int, $day_int);
    } else if ($month_int !== null && $day_int === null) {
        // Month only, use first day of month
        $lease_date = sprintf('%04d-%02d-01', $year, $month_int);
    } else if ($month_int === null && $day_int !== null) {
        // Day only, use current month
        $current_month = date('m');
        $lease_date = sprintf('%04d-%02d-%02d', $year, $current_month, $day_int);
    } else {
        // No filter, today
        $lease_date = date('Y-m-d');
    }

    $start_date = $lease_date; 
    $in_30_sql = date('Y-m-d', strtotime($start_date . ' +30 days')); 
    $in_60_sql = date('Y-m-d', strtotime($start_date . ' +60 days')); 
    $in_90_sql = date('Y-m-d', strtotime($start_date . ' +90 days'));


/*
    if ($lease_date !== null) { 
    $start_date = $lease_date; 
    } else { 
    $start_date = date('Y-m-d'); 
    } 
    $in_30_sql = date('Y-m-d', strtotime($start_date . ' +30 days'));
    $in_60_sql = date('Y-m-d', strtotime($start_date . ' +60 days')); 
    $in_90_sql = date('Y-m-d', strtotime($start_date . ' +90 days'));
*/

    // get total leases first to validate page number (with filter)
    $con = connect();
    $total_leases = 0;
    if ($con) {
        $count_query = "SELECT COUNT(*) as total FROM dbleases" . $where;
        $count_result = mysqli_query($con, $count_query);
        if ($count_result) {
            $total_leases = (int)mysqli_fetch_assoc($count_result)['total'];
        }
    }
    $total_pages = $items_per_page > 0 ? (int)ceil($total_leases / $items_per_page) : 1;

    // ensure current_page doesn't exceed total pages
    if ($current_page > $total_pages && $total_pages > 0) {
        $current_page = $total_pages;
        $offset = ($current_page - 1) * $items_per_page;
    }

    // get paginated and sorted leases (with filter)
    $leases = [];
    if ($con) {
        $query = "SELECT * FROM dbleases" . $where . " ORDER BY " . $sort_by . " " . $sort_order .
                 " LIMIT " . (int)$items_per_page . " OFFSET " . (int)$offset;
        $result = mysqli_query($con, $query);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) { $leases[] = $row; }
        }
        mysqli_close($con);
    }

    // expiring in 30-day leases 
    $leases_30_days = [];
    $con2 = connect();

    if ($con2) {
        //$today_sql = date('Y-m-d');
        //$today_sql = '2026-10-01';
        //$in_30_sql = date('Y-m-d', strtotime('+30 days'));
        //$in_30_sql = date('Y-m-d', strtotime($today_sql . '+30 days'));

        $query_30 = "
            SELECT *
            FROM dbleases
            WHERE expiration_date >= '$start_date' 
            AND expiration_date < '$in_30_sql'
            ORDER BY expiration_date ASC
        ";

        $result_30 = mysqli_query($con2, $query_30);

        if ($result_30) {
            while ($row = mysqli_fetch_assoc($result_30)) {
                $leases_30_days[] = $row;
            }
        }

        mysqli_close($con2);
    }

    // expiring in 60-day leases 
    $leases_60_days = [];
    $con3 = connect();

    if ($con3) {

        $query_60 = "
            SELECT *
            FROM dbleases
            WHERE expiration_date >= '$in_30_sql' 
            AND expiration_date < '$in_60_sql'
            ORDER BY expiration_date ASC
        ";

        $result_60 = mysqli_query($con3, $query_60);

        if ($result_60) {
            while ($row = mysqli_fetch_assoc($result_60)) {
                $leases_60_days[] = $row;
            }
        }

        mysqli_close($con3);
    }

    // expiring in 90-day leases 
    $leases_90_days = [];
    $con4 = connect();

    if ($con4) {

        $query_90 = "
            SELECT *
            FROM dbleases
            WHERE expiration_date >= '$in_60_sql' 
            AND expiration_date < '$in_90_sql'
            ORDER BY expiration_date ASC
        ";

        $result_90 = mysqli_query($con4, $query_90);

        if ($result_90) {
            while ($row = mysqli_fetch_assoc($result_90)) {
                $leases_90_days[] = $row;
            }
        }

        mysqli_close($con4);
    }

    $all_leases = [];
    $con_all = connect();

    if ($con_all) {
        /*$query_all = "
            SELECT *
            FROM dbleases
            ORDER BY expiration_date ASC
            LIMIT 50
        ";*/
        $query_all = "
            SELECT *
            FROM dbleases
            ORDER BY $sort_by $sort_order
            LIMIT 50
        ";


        $result_all = mysqli_query($con_all, $query_all);

        if ($result_all) {
            while ($row = mysqli_fetch_assoc($result_all)) {
                $all_leases[] = $row;
            }
        }

        mysqli_close($con_all);
    }


    // helper: keep month param in links
    function monthQS() {
        return (isset($_GET['exp_month']) && $_GET['exp_month'] !== '')
            ? "&exp_month=" . urlencode($_GET['exp_month'])
            : "";
    }

    // helper function to generate sort URLs (preserve month + page)
    function getSortUrl($column) {
        global $sort_by, $sort_order, $current_page;
        $new_order = ($sort_by == $column && $sort_order == 'ASC') ? 'DESC' : 'ASC';
        return "?page=" . $current_page . "&sort=" . $column . "&order=" . $new_order . monthQS();
    }

    // helper function to get sort arrow
    function getSortArrow($column) {
        global $sort_by, $sort_order;
        if ($sort_by == $column) {
            return $sort_order == 'ASC' ? ' ↑' : ' ↓';
        }
        return '';
    }

    // month names for dropdown
    $month_names = [
        1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
        7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
    ];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" href="images/micah-favicon.png">
    <link href="css/base.css?v=<?php echo time(); ?>" rel="stylesheet">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Leases</title>

    <!-- BANDAID FIX FOR HEADER BEING WEIRD -->
    <?php
        $tailwind_mode = true;
        require_once('header.php');
    ?>
</head>
<body>

    <!-- Hero Section with Ribbon -->
    <div class="hero-header">
        <div class="center-header">
            <h1>All Leases</h1>
        </div>
    </div>

    <!-- Main Content -->
    <main>
        <div class="sections">

            <!-- Navigation Section - Removed -->
            <div class="button-section"></div>

            <!-- Text Section -->
            <div class="text-section">
                <div class="main-content-box p-6">

                    <p class="secondary-text" style="margin-bottom: 10px; text-align: left;">
                        View and manage all leases. Use the table below to see lease details, tenant information, and property details.
                    </p>

                    <!-- NEW: Month filter -->
                    <form class="filter-bar" method="get">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_by); ?>">
                        <input type="hidden" name="order" value="<?php echo htmlspecialchars($sort_order); ?>">
                        <input type="hidden" name="page" value="1">

                        <label for="exp_month">Expires in (month):</label>
                        <select id="exp_month" name="exp_month">
                            <option value="">All months</option>
                            <?php foreach ($month_names as $num=>$name): ?>
                                <option value="<?php echo $num; ?>" <?php echo ($month_int === $num ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label for="exp_day">(day):</label> 
                        <select id="exp_day" name="exp_day"> 
                            <option value="">All days</option> 
                            <?php for ($d = 1; $d <= 31; $d++): ?> 
                                <option value="<?php echo $d; ?>" <?php echo ($day_int === $d ? 'selected' : ''); ?>> 
                                    <?php echo $d; ?> 
                                </option>
                            <?php endfor; ?> 
                        </select>



                        <button class="apply-btn" type="submit">Apply</button>
                        <a class="reset-btn" href="?page=1&sort=created_at&order=DESC">Reset</a>
                    </form>

                    <!-- Month Table -->
                    <!--
                    <?php if (empty($leases)): ?>
                        <div style="margin-top: 10px; padding: 14px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                            <p><strong>No leases found.</strong></p>
                            <p>Try a different month, or click “Reset”.</p>
                        </div>

                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="sortable-table">
                                <thead>
                                    <tr>
                                        <th><a href="<?php echo getSortUrl('id'); ?>">Lease ID<?php echo getSortArrow('id'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('tenant_first_name'); ?>">Tenant Name<?php echo getSortArrow('tenant_first_name'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('property_street'); ?>">Property Address<?php echo getSortArrow('property_street'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('unit_number'); ?>">Unit<?php echo getSortArrow('unit_number'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('program_type'); ?>">Program Type<?php echo getSortArrow('program_type'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('expiration_date'); ?>">Expiration Date<?php echo getSortArrow('expiration_date'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('status'); ?>">Status<?php echo getSortArrow('status'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('monthly_rent'); ?>">Monthly Rent<?php echo getSortArrow('monthly_rent'); ?></a></th>
                                        <th><a href="<?php echo getSortUrl('case_manager'); ?>">Case Manager<?php echo getSortArrow('case_manager'); ?></a></th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leases as $lease): ?>
                                        <tr>
                                            <td><a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" style="font-weight:bold;"><?php echo htmlspecialchars($lease['id']); ?></a></td>
                                            <td><?php echo htmlspecialchars($lease['tenant_first_name'].' '.$lease['tenant_last_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($lease['property_street']); ?><br>
                                                <small><?php echo htmlspecialchars($lease['property_city'].', '.$lease['property_state'].' '.$lease['property_zip']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['program_type'] ?: 'N/A'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($lease['expiration_date'])); ?></td>
                                            <td><span class="status-<?php echo strtolower($lease['status']); ?>"><?php echo htmlspecialchars($lease['status']); ?></span></td>
                                            <td><?php echo $lease['monthly_rent'] ? '$'.number_format($lease['monthly_rent'],2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($lease['case_manager']); ?></td>
                                            <td>
                                                <a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" class="blue-button" style="color:white;">Edit</a>
                                                <a href="deletelease.php?id=<?php echo urlencode($lease['id']); ?>" class="delete-button" style="color:white;">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    -->
                    <h2 class="lease-section-title">All Leases</h2>

                    <!-- ALL LEASES -->
                    <?php if (empty($all_leases)): ?>
                        <div style="margin-top: 10px; padding: 14px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                            <p><strong>No leases found.</strong></p>
                        </div>

                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="sortable-table">
                                <thead>
                                    <tr>
                                        <th>Lease ID</th>
                                        <th>Tenant Name</th>
                                        <th>Property Address</th>
                                        <th>Unit</th>
                                        <th>Program Type</th>
                                        <th> <a href="<?php echo getSortUrl('expiration_date'); ?>">Expiration Date <?php echo getSortArrow('expiration_date'); ?></a></th>
                                        <th>Status</th>
                                        <th>Monthly Rent</th>
                                        <th>Case Manager</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_leases as $lease): ?>
                                        <tr>
                                            <td><a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" style="font-weight:bold;"><?php echo htmlspecialchars($lease['id']); ?></a></td>
                                            <td><?php echo htmlspecialchars($lease['tenant_first_name'].' '.$lease['tenant_last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['property_street']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['program_type'] ?: 'N/A'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($lease['expiration_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($lease['status']); ?></td>
                                            <td><?php echo $lease['monthly_rent'] ? '$'.number_format($lease['monthly_rent'],2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($lease['case_manager']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- 30 DAYS SECTION -->
                    <h2 class="lease-section-title">Expires in 30 Days</h2>

                    <?php if (empty($leases_30_days)): ?>
                        <div style="margin-top: 10px; padding: 14px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                            <p><strong>No leases found.</strong></p>
                        </div>

                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="sortable-table">
                                <thead>
                                    <tr>
                                        <th>Lease ID</th>
                                        <th>Tenant Name</th>
                                        <th>Property Address</th>
                                        <th>Unit</th>
                                        <th>Program Type</th>
                                        <th> <a href="<?php echo getSortUrl('expiration_date'); ?>">Expiration Date <?php echo getSortArrow('expiration_date'); ?></a></th>
                                        <th>Status</th>
                                        <th>Monthly Rent</th>
                                        <th>Case Manager</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leases_30_days as $lease): ?>
                                        <tr>
                                            <td><a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" style="font-weight:bold;"><?php echo htmlspecialchars($lease['id']); ?></a></td>
                                            <td><?php echo htmlspecialchars($lease['tenant_first_name'].' '.$lease['tenant_last_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($lease['property_street']); ?><br>
                                                <small><?php echo htmlspecialchars($lease['property_city'].', '.$lease['property_state'].' '.$lease['property_zip']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['program_type'] ?: 'N/A'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($lease['expiration_date'])); ?></td>
                                            <td><span class="status-<?php echo strtolower($lease['status']); ?>"><?php echo htmlspecialchars($lease['status']); ?></span></td>
                                            <td><?php echo $lease['monthly_rent'] ? '$'.number_format($lease['monthly_rent'],2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($lease['case_manager']); ?></td>
                                            <td>
                                                <a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" class="blue-button" style="color:white;">Edit</a>
                                                <a href="deletelease.php?id=<?php echo urlencode($lease['id']); ?>" class="delete-button" style="color:white;">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>


                    <!-- 60 DAYS SECTION -->
                    <h2 class="lease-section-title">Expires in 60 Days</h2>

                    <?php if (empty($leases_60_days)): ?>
                        <div style="margin-top: 10px; padding: 14px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                            <p><strong>No leases found.</strong></p>
                        </div>

                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="sortable-table">
                                <thead>
                                    <tr>
                                        <th>Lease ID</th>
                                        <th>Tenant Name</th>
                                        <th>Property Address</th>
                                        <th>Unit</th>
                                        <th>Program Type</th>
                                        <th> <a href="<?php echo getSortUrl('expiration_date'); ?>">Expiration Date <?php echo getSortArrow('expiration_date'); ?></a></th>
                                        <th>Status</th>
                                        <th>Monthly Rent</th>
                                        <th>Case Manager</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leases_60_days as $lease): ?>
                                        <tr>
                                            <td><a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" style="font-weight:bold;"><?php echo htmlspecialchars($lease['id']); ?></a></td>
                                            <td><?php echo htmlspecialchars($lease['tenant_first_name'].' '.$lease['tenant_last_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($lease['property_street']); ?><br>
                                                <small><?php echo htmlspecialchars($lease['property_city'].', '.$lease['property_state'].' '.$lease['property_zip']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                                            <td><?php echo htmlspecialchars($lease['program_type'] ?: 'N/A'); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($lease['expiration_date'])); ?></td>
                                            <td><span class="status-<?php echo strtolower($lease['status']); ?>"><?php echo htmlspecialchars($lease['status']); ?></span></td>
                                            <td><?php echo $lease['monthly_rent'] ? '$'.number_format($lease['monthly_rent'],2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($lease['case_manager']); ?></td>
                                            <td>
                                                <a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" class="blue-button" style="color:white;">Edit</a>
                                                <a href="deletelease.php?id=<?php echo urlencode($lease['id']); ?>" class="delete-button" style="color:white;">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>


                <!-- 90 DAYS SECTION -->
                <h2 class="lease-section-title">Expires in 90 Days</h2>

                <?php if (empty($leases_90_days)): ?>
                    <div style="margin-top: 10px; padding: 14px; background-color:#f8f9fa; border:1px solid #dee2e6; border-radius:5px;">
                        <p><strong>No leases found.</strong></p>
                    </div>

                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="sortable-table">
                            <thead>
                                <tr>
                                    <th>Lease ID</th>
                                    <th>Tenant Name</th>
                                    <th>Property Address</th>
                                    <th>Unit</th>
                                    <th>Program Type</th>
                                    <th> <a href="<?php echo getSortUrl('expiration_date'); ?>">Expiration Date <?php echo getSortArrow('expiration_date'); ?></a></th>
                                    <th>Status</th>
                                    <th>Monthly Rent</th>
                                    <th>Case Manager</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leases_90_days as $lease): ?>
                                    <tr>
                                        <td><a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" style="font-weight:bold;"><?php echo htmlspecialchars($lease['id']); ?></a></td>
                                        <td><?php echo htmlspecialchars($lease['tenant_first_name'].' '.$lease['tenant_last_name']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($lease['property_street']); ?><br>
                                            <small><?php echo htmlspecialchars($lease['property_city'].', '.$lease['property_state'].' '.$lease['property_zip']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($lease['unit_number']); ?></td>
                                        <td><?php echo htmlspecialchars($lease['program_type'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y', strtotime($lease['expiration_date'])); ?></td>
                                        <td><span class="status-<?php echo strtolower($lease['status']); ?>"><?php echo htmlspecialchars($lease['status']); ?></span></td>
                                        <td><?php echo $lease['monthly_rent'] ? '$'.number_format($lease['monthly_rent'],2) : 'N/A'; ?></td>
                                        <td><?php echo htmlspecialchars($lease['case_manager']); ?></td>
                                        <td>
                                            <a href="editLease.php?id=<?php echo urlencode($lease['id']); ?>" class="blue-button" style="color:white;">Edit</a>
                                            <a href="deletelease.php?id=<?php echo urlencode($lease['id']); ?>" class="delete-button" style="color:white;">Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

 
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container" style="margin-top: 20px; text-align: center;">
                            <div class="pagination-info" style="margin-bottom: 10px; color: #666;">
                                Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $items_per_page, $total_leases); ?> of <?php echo $total_leases; ?> leases
                            </div>

                            <div class="pagination-buttons">
                                <?php if ($current_page > 1): ?>
                                    <a href="?page=1&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?><?php echo monthQS(); ?>" class="pagination-btn">First</a>
                                    <a href="?page=<?php echo $current_page - 1; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?><?php echo monthQS(); ?>" class="pagination-btn">Previous</a>
                                <?php endif; ?>

                                <?php
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_pages, $current_page + 2);

                                    for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="?page=<?php echo $i; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?><?php echo monthQS(); ?>"
                                       class="pagination-btn <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?page=<?php echo $current_page + 1; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?><?php echo monthQS(); ?>" class="pagination-btn">Next</a>
                                    <a href="?page=<?php echo $total_pages; ?>&sort=<?php echo $sort_by; ?>&order=<?php echo $sort_order; ?><?php echo monthQS(); ?>" class="pagination-btn">Last</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </main>
</body>
</html>