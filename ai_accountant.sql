-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 01, 2026 at 07:46 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ai_accountant`
--

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `transaction_type` enum('starting_balance','income','expense','lend','borrow','investment','loss') NOT NULL,
  `subject` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `party_name` varchar(255) DEFAULT NULL,
  `date_ad` date NOT NULL,
  `date_bs` varchar(10) NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `transaction_type`, `subject`, `amount`, `party_name`, `date_ad`, `date_bs`, `remarks`, `created_at`) VALUES
(8, 3, 'starting_balance', 'Nic Asia bank', 10.00, NULL, '2026-08-01', '2083-04-16', 'as', '2026-08-01 05:24:38'),
(9, 3, 'starting_balance', 'esewa', 120.00, NULL, '2026-08-01', '2083-04-16', '', '2026-08-01 05:24:51'),
(10, 3, 'income', 'Salarly', 1200.00, NULL, '2026-08-01', '2083-04-16', '', '2026-08-01 05:25:07'),
(11, 3, 'expense', 'petrol', 1000.00, NULL, '2026-08-01', '2083-04-16', '', '2026-08-01 05:25:18'),
(12, 3, 'lend', 'madam', 1200.00, NULL, '2026-08-01', '2083-04-16', '1', '2026-08-01 05:25:28'),
(13, 3, 'borrow', 'boewq', 45646.00, NULL, '2026-08-01', '2083-04-16', '', '2026-08-01 05:25:41'),
(14, 3, 'investment', 'aklsd', 47874.00, NULL, '2026-08-01', '2083-04-16', '8747', '2026-08-01 05:25:50'),
(15, 3, 'loss', 'mlkn', 4684.00, NULL, '2026-08-01', '2083-04-16', '44', '2026-08-01 05:26:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_photo` varchar(255) DEFAULT 'default.png',
  `joined_date_bs` varchar(10) DEFAULT '2083-04-16',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `username`, `password`, `profile_photo`, `joined_date_bs`, `created_at`) VALUES
(3, 'Anup Dahal', 'dahal6270@gmail.com', '9804902634', 'Anup', '$2y$10$PVyGHAaevFRnyBtf0Mkzuekx2p/Ot98.bY4yQrY4/2obHvsg5W1ES', '1785559193_6a6d789954e73.jpeg', '2083-04-16', '2026-08-01 04:39:53');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
