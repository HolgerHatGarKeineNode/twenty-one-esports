// Start value per territory, measured: start-holder win rate minus fair share (4 players, pp).
// Zuerich never starts owned (a one-territory currency space), so it has no value.
pub const T_VALUE: [f64; crate::map::NT] = [-0.31, -0.49, -0.21, -1.4, -0.75, 0.51, 6.0, 6.7, 7.6, 7.0, -1.2, 0.21, -1.1, 0.35, -0.96, 0.58, 0.56, 0.08, 0.0, -3.5, -3.3, -3.1, -2.2, -4.9, -3.2, -1.9, -1.9, -2.6, -2.2, -1.8, -1.8, -1.3, -1.6, -1.8, -1.7, -2.4, -0.21, 0.43, 0.06, -0.02, -0.18, 0.03, 0.15, 0.75, -0.07, 0.34, 0.2, 0.29, 0.5, 3.4, 2.9, 4.1, 5.2];
