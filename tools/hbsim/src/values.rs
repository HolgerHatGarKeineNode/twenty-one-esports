// Generated from the game page by maprs.mjs. Do not edit.
// Start value per territory (START_VALUE): start-holder win rate minus fair share (4 players, pp).
// Zuerich never starts owned (a one-territory currency space), so it has no value.
pub const T_VALUE: [f64; crate::map::NT] = [-0.31, -0.49, -0.21, -1.45, -0.75, 0.51, 6.02, 6.7, 7.63, 7.01, -1.2, 0.21, -1.08, 0.35, -0.96, 0.58, 0.56, 0.08, 0.0, -3.49, -3.26, -3.13, -2.2, -4.9, -3.23, -1.88, -1.88, -2.62, -2.17, -1.81, -1.78, -1.33, -1.59, -1.75, -1.73, -2.38, -0.21, 0.43, 0.06, -0.02, -0.18, 0.03, 0.15, 0.75, -0.07, 0.34, 0.2, 0.29, 0.5, 3.44, 2.89, 4.07, 5.21];
// Seat compensation (SEAT_COMP): extra start plebs per later seat, index = players - 2.
pub const SEAT_COMP_OPEN: [f64; 5] = [9.5, 6.0, 4.5, 2.0, 1.5];
pub const SEAT_COMP_LIMIT: [f64; 5] = [9.5, 6.0, 4.0, 1.5, 1.0];
// Team games (P4), two teams seated alternately: index 0 = 4 seats (2v2), 1 = 6 seats (3v3).
pub const TEAM_COMP_OPEN: [f64; 2] = [4.0, 1.0];
pub const TEAM_COMP_LIMIT: [f64; 2] = [4.0, 1.0];
